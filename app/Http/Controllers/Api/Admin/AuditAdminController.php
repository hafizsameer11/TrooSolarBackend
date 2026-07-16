<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Helpers\ResponseHelper;
use App\Mail\AuditStatusEmail;
use App\Models\AuditRequest;
use App\Models\CartItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Exception;

class AuditAdminController extends Controller
{
    private function formatApprovalPaymentFields(AuditRequest $request): array
    {
        if (! Schema::hasColumn('audit_requests', 'approval_payment_date')) {
            return [];
        }

        return [
            'approval_payment_date' => $request->approval_payment_date?->format('Y-m-d'),
            'approval_payment_time' => $request->approval_payment_time,
            'approval_payment_amount' => $request->approval_payment_amount !== null
                ? (float) $request->approval_payment_amount
                : null,
            'approval_payment_account_details' => $request->approval_payment_account_details,
        ];
    }

    private function formatCustomerPaymentFields(AuditRequest $request): array
    {
        if (! Schema::hasColumn('audit_requests', 'customer_has_paid')) {
            return [];
        }

        return [
            'customer_has_paid' => (bool) $request->customer_has_paid,
            'customer_payment_date' => $request->customer_payment_date?->format('Y-m-d'),
            'customer_payment_time' => $request->customer_payment_time,
        ];
    }
    /**
     * Get all users who have made audit requests
     * GET /api/admin/audit/users-with-requests
     */
    public function getUsersWithAuditRequests(Request $request)
    {
        try {
            // Start with users who have audit requests
            // First, filter by audit_type if specified (before join to optimize)
            $auditTypeFilter = null;
            if ($request->has('audit_type') && $request->audit_type !== 'all') {
                $auditType = $request->audit_type;
                if (in_array($auditType, ['home-office', 'commercial'])) {
                    $auditTypeFilter = $auditType;
                }
            }

            // Get users who have audit requests with aggregated data
            $query = User::select([
                'users.id',
                'users.first_name',
                'users.sur_name',
                'users.email',
                'users.phone',
                'users.created_at',
                DB::raw('COUNT(DISTINCT audit_requests.id) as audit_request_count'),
                DB::raw('SUM(CASE WHEN audit_requests.status = "pending" THEN 1 ELSE 0 END) as pending_count'),
                DB::raw('SUM(CASE WHEN audit_requests.status = "approved" THEN 1 ELSE 0 END) as approved_count'),
                DB::raw('SUM(CASE WHEN audit_requests.status = "rejected" THEN 1 ELSE 0 END) as rejected_count'),
                DB::raw('SUM(CASE WHEN audit_requests.status = "completed" THEN 1 ELSE 0 END) as completed_count'),
                DB::raw('SUM(CASE WHEN audit_requests.order_id IS NOT NULL THEN 1 ELSE 0 END) as audit_orders_count'),
                DB::raw('MAX(audit_requests.created_at) as last_audit_request_date'),
                DB::raw('(SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id) as total_orders'),
                DB::raw('(SELECT COUNT(*) FROM orders WHERE orders.user_id = users.id AND (orders.order_type = "audit_only" OR orders.audit_request_id IS NOT NULL)) as audit_related_orders_count'),
            ])
                ->leftJoin('audit_requests', function ($join) use ($auditTypeFilter) {
                    $join->on('audit_requests.user_id', '=', 'users.id');
                    if ($auditTypeFilter) {
                        $join->where('audit_requests.audit_type', '=', $auditTypeFilter);
                    }
                })
                ->groupBy('users.id', 'users.first_name', 'users.sur_name', 'users.email', 'users.phone', 'users.created_at')
                ->havingRaw('COUNT(audit_requests.id) > 0'); // Only users with audit requests

            // Search functionality
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('users.first_name', 'like', "%{$search}%")
                        ->orWhere('users.sur_name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%")
                        ->orWhere('users.phone', 'like', "%{$search}%");
                });
            }

            // Audit type filtering is now handled in the join (above) for better performance

            // Filter by status
            if ($request->has('has_pending')) {
                $query->havingRaw('SUM(CASE WHEN audit_requests.status = "pending" THEN 1 ELSE 0 END) > 0');
            }

            // Sort functionality
            $sortBy = $request->get('sort_by', 'last_audit_request_date');
            $sortOrder = $request->get('sort_order', 'desc');

            $allowedSorts = ['name', 'email', 'audit_request_count', 'last_audit_request_date', 'created_at'];
            if (!in_array($sortBy, $allowedSorts)) {
                $sortBy = 'last_audit_request_date';
            }

            if ($sortBy === 'name') {
                $query->orderBy('users.first_name', $sortOrder)
                    ->orderBy('users.sur_name', $sortOrder);
            } elseif ($sortBy === 'email') {
                $query->orderBy('users.email', $sortOrder);
            } elseif ($sortBy === 'audit_request_count') {
                $query->orderBy('audit_request_count', $sortOrder);
            } elseif ($sortBy === 'last_audit_request_date') {
                $query->orderBy('last_audit_request_date', $sortOrder);
            } else {
                $query->orderBy('users.created_at', $sortOrder);
            }

            // Pagination
            $perPage = $request->get('per_page', 15);

            // Debug: Log the query SQL
            Log::info('Audit Users Query', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
                'audit_type_filter' => $auditTypeFilter
            ]);

            $users = $query->paginate($perPage);

            // If no users found, still return empty result properly
            if ($users->isEmpty()) {
                // Check if there are any audit requests at all (for debugging)
                $totalAuditRequests = AuditRequest::count();
                Log::info('No users with audit requests found', [
                    'total_audit_requests_in_db' => $totalAuditRequests,
                    'audit_type_filter' => $auditTypeFilter
                ]);
            }

            // Get all user IDs for batch loading audit requests
            $userIds = $users->pluck('id');

            // Batch load audit requests for all users (respect audit_type filter if set)
            if ($userIds->isNotEmpty()) {
                $auditRequestsQuery = AuditRequest::whereIn('user_id', $userIds);
                if ($auditTypeFilter) {
                    $auditRequestsQuery->where('audit_type', $auditTypeFilter);
                }
                $allAuditRequests = $auditRequestsQuery
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->groupBy('user_id');
            } else {
                $allAuditRequests = collect();
            }

            $cartStats = $userIds->isNotEmpty()
                ? CartItem::whereIn('user_id', $userIds)
                    ->selectRaw('user_id, COUNT(*) as cart_item_count, COALESCE(SUM(subtotal), 0) as total_cart_amount')
                    ->groupBy('user_id')
                    ->get()
                    ->keyBy('user_id')
                : collect();

            $usersWithTokens = $userIds->isNotEmpty()
                ? User::whereIn('id', $userIds)->pluck('cart_access_token', 'id')
                : collect();

            // Format response
            $formattedData = collect($users->items())->map(function ($user) use ($allAuditRequests, $cartStats, $usersWithTokens) {
                // Get audit requests for this user
                $auditRequests = $allAuditRequests->get($user->id, collect());

                // Format audit requests
                $requests = $auditRequests->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'audit_type' => $request->audit_type,
                        'audit_subtype' => $request->audit_subtype,
                        'customer_type' => $request->customer_type,
                        'company_name' => $request->company_name,
                        'status' => $request->status,
                        'property_state' => $request->property_state,
                        'property_address' => $request->property_address,
                        'contact_name' => $request->contact_name,
                        'contact_phone' => $request->contact_phone,
                        'property_landmark' => $request->property_landmark,
                        'building_type' => $request->building_type,
                        'facility_description' => $request->facility_description,
                        'property_floors' => $request->property_floors,
                        'property_rooms' => $request->property_rooms,
                        'is_gated_estate' => $request->is_gated_estate,
                        'has_property_details' => !empty($request->property_address),
                        'preferred_audit_date' => $request->preferred_audit_date?->format('Y-m-d'),
                        'preferred_audit_time' => $request->preferred_audit_time,
                        'needs_admin_input' => $request->audit_type === 'commercial'
                            && empty($request->property_address)
                            && $request->status === 'pending',
                        'order_id' => $request->order_id,
                        'created_at' => $request->created_at ? $request->created_at->format('Y-m-d H:i:s') : null,
                    ];
                })->values();

                $cart = $cartStats->get($user->id);

                return [
                    'id' => $user->id,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->sur_name ?? '')),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'cart_item_count' => (int) ($cart->cart_item_count ?? 0),
                    'total_cart_amount' => (float) ($cart->total_cart_amount ?? 0),
                    'has_cart_access_token' => !empty($usersWithTokens[$user->id]),
                    'audit_request_count' => (int) $user->audit_request_count,
                    'pending_count' => (int) $user->pending_count,
                    'approved_count' => (int) $user->approved_count,
                    'rejected_count' => (int) $user->rejected_count,
                    'completed_count' => (int) $user->completed_count,
                    'audit_orders_count' => (int) ($user->audit_orders_count ?? 0), // Count of audit requests that have orders
                    'total_orders' => (int) ($user->total_orders ?? 0), // Total number of all orders for this user
                    'audit_related_orders_count' => (int) ($user->audit_related_orders_count ?? 0), // Count of orders that are audit-related (order_type='audit_only' or has audit_request_id)
                    'last_audit_request_date' => $user->last_audit_request_date ? date('Y-m-d H:i:s', strtotime($user->last_audit_request_date)) : null,
                    'user_created_at' => $user->created_at ? $user->created_at->format('Y-m-d H:i:s') : null,
                    'audit_requests' => $requests,
                ];
            });

            return ResponseHelper::success([
                'data' => $formattedData,
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'from' => $users->firstItem(),
                    'to' => $users->lastItem(),
                ],
            ], 'Users with audit requests retrieved successfully');

        } catch (Exception $e) {
            Log::error('Error fetching users with audit requests: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to retrieve users with audit requests: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get all audit requests
     * GET /api/admin/audit/requests
     */
    public function index(Request $request)
    {
        try {
            $query = AuditRequest::with(['user:id,first_name,sur_name,email,phone', 'order:id,order_number,total_price,payment_status', 'approver:id,first_name,sur_name,email']);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by audit type (support 'all' to show all types)
            if ($request->has('audit_type') && $request->audit_type !== 'all') {
                $auditType = $request->audit_type;
                if (in_array($auditType, ['home-office', 'commercial'])) {
                    $query->where('audit_type', $auditType);
                }
            }

            // Search by user name, email, or property address
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', "%{$search}%")
                            ->orWhere('sur_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                        ->orWhere('property_address', 'like', "%{$search}%")
                        ->orWhere('property_state', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('contact_phone', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
            }

            $auditRequests = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            // Format the response to include additional helpful fields
            $formattedData = collect($auditRequests->items())->map(function ($request) {
                return [
                    'id' => $request->id,
                    'audit_type' => $request->audit_type,
                    'audit_subtype' => $request->audit_subtype,
                    'customer_type' => $request->customer_type,
                    'company_name' => $request->company_name,
                    'source' => $request->source,
                    'status' => $request->status,
                    'user' => $request->user ? [
                        'id' => $request->user->id,
                        'first_name' => $request->user->first_name,
                        'sur_name' => $request->user->sur_name,
                        'name' => trim(($request->user->first_name ?? '') . ' ' . ($request->user->sur_name ?? '')),
                        'email' => $request->user->email,
                        'phone' => $request->user->phone,
                    ] : null,
                    'property_state' => $request->property_state,
                    'property_address' => $request->property_address,
                    'contact_name' => $request->contact_name,
                    'contact_phone' => $request->contact_phone,
                    'property_landmark' => $request->property_landmark,
                    'building_type' => $request->building_type,
                    'facility_description' => $request->facility_description,
                    'property_floors' => $request->property_floors,
                    'property_rooms' => $request->property_rooms,
                    'is_gated_estate' => $request->is_gated_estate,
                    'estate_name' => $request->estate_name,
                    'estate_address' => $request->estate_address,
                    'preferred_audit_date' => $request->preferred_audit_date?->format('Y-m-d'),
                    'preferred_audit_time' => $request->preferred_audit_time,
                    'has_property_details' => !empty($request->property_address), // Indicates if user provided property details
                    'needs_admin_input' => $request->audit_type === 'commercial' && empty($request->property_address), // Commercial requests may need admin to gather details
                    'admin_notes' => $request->admin_notes,
                    ...$this->formatApprovalPaymentFields($request),
                    ...$this->formatCustomerPaymentFields($request),
                    'approved_by' => $request->approver ? [
                        'id' => $request->approver->id,
                        'name' => trim(($request->approver->first_name ?? '') . ' ' . ($request->approver->sur_name ?? '')),
                        'email' => $request->approver->email,
                    ] : null,
                    'approved_at' => $request->approved_at?->toIso8601String(),
                    'order' => $request->order ? [
                        'id' => $request->order->id,
                        'order_number' => $request->order->order_number,
                        'total_price' => $request->order->total_price,
                        'payment_status' => $request->order->payment_status,
                    ] : null,
                    'created_at' => $request->created_at->toIso8601String(),
                    'updated_at' => $request->updated_at->toIso8601String(),
                ];
            });

            return ResponseHelper::success([
                'data' => $formattedData,
                'pagination' => [
                    'current_page' => $auditRequests->currentPage(),
                    'last_page' => $auditRequests->lastPage(),
                    'per_page' => $auditRequests->perPage(),
                    'total' => $auditRequests->total(),
                    'from' => $auditRequests->firstItem(),
                    'to' => $auditRequests->lastItem(),
                ],
            ], 'Audit requests retrieved successfully');
        } catch (Exception $e) {
            Log::error('Audit Admin Index Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve audit requests', 500);
        }
    }

    /**
     * Get single audit request details
     * GET /api/admin/audit/requests/{id}
     */
    public function show($id)
    {
        try {
            $auditRequest = AuditRequest::with([
                'user:id,first_name,sur_name,email,phone',
                'order:id,order_number,total_price,payment_status,order_status',
                'approver:id,first_name,sur_name,email'
            ])->findOrFail($id);

            return ResponseHelper::success([
                'id' => $auditRequest->id,
                'user' => [
                    'id' => $auditRequest->user->id,
                    'first_name' => $auditRequest->user->first_name,
                    'sur_name' => $auditRequest->user->sur_name,
                    'name' => trim(($auditRequest->user->first_name ?? '') . ' ' . ($auditRequest->user->sur_name ?? '')),
                    'email' => $auditRequest->user->email,
                    'phone' => $auditRequest->user->phone,
                ],
                'audit_type' => $auditRequest->audit_type,
                'audit_subtype' => $auditRequest->audit_subtype,
                'customer_type' => $auditRequest->customer_type,
                'company_name' => $auditRequest->company_name,
                'source' => $auditRequest->source,
                'property_state' => $auditRequest->property_state,
                'property_address' => $auditRequest->property_address,
                'contact_name' => $auditRequest->contact_name,
                'contact_phone' => $auditRequest->contact_phone,
                'property_landmark' => $auditRequest->property_landmark,
                'building_type' => $auditRequest->building_type,
                'facility_description' => $auditRequest->facility_description,
                'property_floors' => $auditRequest->property_floors,
                'property_rooms' => $auditRequest->property_rooms,
                'is_gated_estate' => $auditRequest->is_gated_estate,
                'estate_name' => $auditRequest->estate_name,
                'estate_address' => $auditRequest->estate_address,
                'preferred_audit_date' => $auditRequest->preferred_audit_date?->format('Y-m-d'),
                'preferred_audit_time' => $auditRequest->preferred_audit_time,
                'status' => $auditRequest->status,
                'admin_notes' => $auditRequest->admin_notes,
                ...$this->formatApprovalPaymentFields($auditRequest),
                ...$this->formatCustomerPaymentFields($auditRequest),
                'approved_by' => $auditRequest->approver ? [
                    'id' => $auditRequest->approver->id,
                    'name' => $auditRequest->approver->first_name . ' ' . $auditRequest->approver->sur_name,
                    'email' => $auditRequest->approver->email,
                ] : null,
                'approved_at' => $auditRequest->approved_at?->toIso8601String(),
                'order' => $auditRequest->order ? [
                    'id' => $auditRequest->order->id,
                    'order_number' => $auditRequest->order->order_number,
                    'total_price' => $auditRequest->order->total_price,
                    'payment_status' => $auditRequest->order->payment_status,
                    'order_status' => $auditRequest->order->order_status,
                ] : null,
                'created_at' => $auditRequest->created_at->toIso8601String(),
                'updated_at' => $auditRequest->updated_at->toIso8601String(),
            ], 'Audit request retrieved successfully');
        } catch (Exception $e) {
            Log::error('Audit Admin Show Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve audit request', 500);
        }
    }

    /**
     * Approve or reject audit request
     * PUT /api/admin/audit/requests/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $data = $request->validate([
                'status' => 'required|in:approved,rejected,completed',
                'admin_notes' => 'nullable|string|max:2000',
                'property_state' => 'nullable|string|max:255',
                'property_address' => 'nullable|string',
                'contact_name' => 'nullable|string|max:255',
                'contact_phone' => 'nullable|string|max:30',
                'approval_payment_date' => [
                    Rule::requiredIf(fn () => $request->input('status') === 'approved'),
                    'nullable',
                    'date',
                ],
                'approval_payment_time' => [
                    Rule::requiredIf(fn () => $request->input('status') === 'approved'),
                    'nullable',
                    'string',
                    'max:20',
                ],
                'approval_payment_amount' => [
                    Rule::requiredIf(fn () => $request->input('status') === 'approved'),
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'approval_payment_account_details' => [
                    Rule::requiredIf(fn () => $request->input('status') === 'approved'),
                    'nullable',
                    'string',
                    'max:2000',
                ],
                'customer_has_paid' => 'nullable|boolean',
                'customer_payment_date' => [
                    Rule::requiredIf(fn () => filter_var($request->input('customer_has_paid'), FILTER_VALIDATE_BOOLEAN)),
                    'nullable',
                    'date',
                ],
                'customer_payment_time' => [
                    Rule::requiredIf(fn () => filter_var($request->input('customer_has_paid'), FILTER_VALIDATE_BOOLEAN)),
                    'nullable',
                    'string',
                    'max:20',
                ],
            ]);

            $auditRequest = AuditRequest::findOrFail($id);
            $previousStatus = $auditRequest->status;

            $auditRequest->status = $data['status'];
            $auditRequest->admin_notes = $data['admin_notes'] ?? $auditRequest->admin_notes;
            $auditRequest->property_state = $data['property_state'] ?? $auditRequest->property_state;
            $auditRequest->property_address = $data['property_address'] ?? $auditRequest->property_address;
            $auditRequest->contact_name = $data['contact_name'] ?? $auditRequest->contact_name;
            $auditRequest->contact_phone = $data['contact_phone'] ?? $auditRequest->contact_phone;

            if (Schema::hasColumn('audit_requests', 'approval_payment_date')) {
                if (array_key_exists('approval_payment_date', $data)) {
                    $auditRequest->approval_payment_date = $data['approval_payment_date'] ?: null;
                }
                if (array_key_exists('approval_payment_time', $data)) {
                    $auditRequest->approval_payment_time = ! empty($data['approval_payment_time'])
                        ? (string) $data['approval_payment_time']
                        : null;
                }
                if (array_key_exists('approval_payment_amount', $data)) {
                    $auditRequest->approval_payment_amount = $data['approval_payment_amount'] !== null
                        ? (float) $data['approval_payment_amount']
                        : null;
                }
                if (array_key_exists('approval_payment_account_details', $data)) {
                    $auditRequest->approval_payment_account_details = ! empty($data['approval_payment_account_details'])
                        ? (string) $data['approval_payment_account_details']
                        : null;
                }
            }

            if (Schema::hasColumn('audit_requests', 'customer_has_paid') && array_key_exists('customer_has_paid', $data)) {
                $hasPaid = filter_var($data['customer_has_paid'], FILTER_VALIDATE_BOOLEAN);
                $auditRequest->customer_has_paid = $hasPaid;
                if ($hasPaid) {
                    $auditRequest->customer_payment_date = $data['customer_payment_date'] ?? null;
                    $auditRequest->customer_payment_time = ! empty($data['customer_payment_time'])
                        ? (string) $data['customer_payment_time']
                        : null;
                } else {
                    $auditRequest->customer_payment_date = null;
                    $auditRequest->customer_payment_time = null;
                }
            }

            if ($data['status'] === 'approved' || $data['status'] === 'completed') {
                if ($previousStatus !== $data['status'] || ! $auditRequest->approved_at) {
                    $auditRequest->approved_by = Auth::id();
                    $auditRequest->approved_at = now();
                }
            }

            $auditRequest->save();

            // Customer emails only when status actually changes to approved/rejected.
            if (
                in_array($data['status'], ['approved', 'rejected'], true)
                && $previousStatus !== $data['status']
            ) {
                $auditRequest->loadMissing('user');
                $user = $auditRequest->user;
                if ($user && !empty($user->email)) {
                    try {
                        Mail::to($user->email)->send(new AuditStatusEmail($user, $auditRequest, $data['status']));
                    } catch (\Throwable $e) {
                        Log::warning('Audit status email failed: ' . $e->getMessage(), [
                            'audit_request_id' => $auditRequest->id,
                            'user_id' => $user->id,
                            'status' => $data['status'],
                        ]);
                    }
                }
            }

            return ResponseHelper::success([
                'id' => $auditRequest->id,
                'status' => $auditRequest->status,
                'admin_notes' => $auditRequest->admin_notes,
                ...$this->formatApprovalPaymentFields($auditRequest),
                ...$this->formatCustomerPaymentFields($auditRequest),
                'property_state' => $auditRequest->property_state,
                'property_address' => $auditRequest->property_address,
                'contact_name' => $auditRequest->contact_name,
                'contact_phone' => $auditRequest->contact_phone,
                'approved_by' => $auditRequest->approver ? [
                    'id' => $auditRequest->approver->id,
                    'name' => $auditRequest->approver->first_name . ' ' . $auditRequest->approver->sur_name,
                ] : null,
                'approved_at' => $auditRequest->approved_at?->toIso8601String(),
            ], 'Audit request status updated successfully');
        } catch (Exception $e) {
            Log::error('Audit Admin Update Status Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to update audit request status', 500);
        }
    }
}
