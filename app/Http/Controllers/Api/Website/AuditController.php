<?php

namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Helpers\ResponseHelper;
use App\Models\AuditRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuditController extends Controller
{
    /**
     * Submit an audit request with property details
     * POST /api/audit/request
     */
    public function submit(Request $request)
    {
        try {
            $auditType = $request->input('audit_type');
            $auditSubtype = $request->input('audit_subtype');
            $isCommercial = $auditType === 'commercial';
            $isHomeOffice = $auditType === 'home-office';
            $isOfficeSubtype = $isHomeOffice && $auditSubtype === 'office';
            // Home path: explicit "home" or legacy requests with no subtype
            $isHomeSubtype = $isHomeOffice && ! $isOfficeSubtype;

            $validationRules = [
                'audit_type' => 'required|in:home-office,commercial',
                'audit_subtype' => 'nullable|in:home,office',
                'customer_type' => 'nullable|in:residential,sme,commercial',
                'product_category' => 'nullable|string|max:100',
                'source' => 'nullable|in:buy_now,bnpl',
                'company_name' => [
                    Rule::requiredIf(fn () => $isCommercial || $isOfficeSubtype),
                    'nullable',
                    'string',
                    'max:255',
                ],
                'facility_description' => [
                    Rule::requiredIf(fn () => $isCommercial),
                    'nullable',
                    'string',
                ],
                'building_type' => [
                    Rule::requiredIf(fn () => $isOfficeSubtype || $isHomeSubtype),
                    'nullable',
                    'string',
                    'max:255',
                ],
                'property_state' => 'required|string|max:255',
                'property_address' => ['required', 'string'],
                'property_landmark' => 'required|string|max:255',
                'property_floors' => [
                    Rule::requiredIf(fn () => $isHomeSubtype || $isOfficeSubtype),
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'property_rooms' => [
                    Rule::requiredIf(fn () => $isHomeSubtype || $isOfficeSubtype),
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'contact_name' => ['required', 'string', 'max:255'],
                'contact_phone' => ['required', 'string', 'max:30'],
                'is_gated_estate' => 'nullable|boolean',
                'estate_name' => 'nullable|required_if:is_gated_estate,true|string|max:255',
                'estate_address' => 'nullable|required_if:is_gated_estate,true|string',
            ];

            if (Schema::hasColumn('audit_requests', 'preferred_audit_date')) {
                $validationRules['preferred_audit_date'] = 'required|date|after_or_equal:today';
            }
            if (Schema::hasColumn('audit_requests', 'preferred_audit_time')) {
                $validationRules['preferred_audit_time'] = ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
            }

            $data = $request->validate($validationRules);

            // Ensure user is authenticated
            $userId = Auth::id();
            if (!$userId) {
                return ResponseHelper::error('User not authenticated', 401);
            }

            // Verify user exists in database (foreign key constraint)
            $user = User::find($userId);
            if (!$user) {
                Log::error('User not found for audit request', ['user_id' => $userId]);
                return ResponseHelper::error('User account not found', 404);
            }

            // Check if audit_requests table exists
            if (!Schema::hasTable('audit_requests')) {
                Log::error('audit_requests table does not exist');
                return ResponseHelper::error('Database table not found. Please run migrations.', 500);
            }

            // Set status to pending for all audit types initially
            $status = 'pending';

            // Prepare data for creation - ensure proper types
            $auditSubtypeValue = isset($data['audit_subtype']) && $data['audit_subtype'] !== ''
                ? (string) $data['audit_subtype']
                : null;

            $auditData = [
                'user_id' => (int) $userId,
                'audit_type' => (string) $data['audit_type'],
                'audit_subtype' => $isHomeOffice ? $auditSubtypeValue : null,
                'status' => (string) $status,
                'customer_type' => !empty($data['customer_type']) ? (string) $data['customer_type'] : null,
                'company_name' => !empty($data['company_name']) ? (string) $data['company_name'] : null,
                'property_state' => !empty($data['property_state']) ? (string) $data['property_state'] : null,
                'property_address' => !empty($data['property_address']) ? (string) $data['property_address'] : null,
                'property_landmark' => !empty($data['property_landmark']) ? (string) $data['property_landmark'] : null,
                'building_type' => !empty($data['building_type']) ? (string) $data['building_type'] : null,
                'facility_description' => !empty($data['facility_description']) ? (string) $data['facility_description'] : null,
                'property_floors' => isset($data['property_floors']) && $data['property_floors'] !== '' ? (int) $data['property_floors'] : null,
                'property_rooms' => isset($data['property_rooms']) && $data['property_rooms'] !== '' ? (int) $data['property_rooms'] : null,
                'contact_name' => !empty($data['contact_name']) ? (string) $data['contact_name'] : null,
                'contact_phone' => !empty($data['contact_phone']) ? (string) $data['contact_phone'] : null,
                'is_gated_estate' => filter_var($data['is_gated_estate'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'estate_name' => !empty($data['estate_name']) ? (string) $data['estate_name'] : null,
                'estate_address' => !empty($data['estate_address']) ? (string) $data['estate_address'] : null,
            ];

            if (Schema::hasColumn('audit_requests', 'product_category')) {
                $auditData['product_category'] = !empty($data['product_category'])
                    ? (string) $data['product_category']
                    : null;
            }

            if (Schema::hasColumn('audit_requests', 'source')) {
                $auditData['source'] = isset($data['source']) && $data['source'] !== ''
                    ? (string) $data['source']
                    : 'bnpl';
            }
            if (Schema::hasColumn('audit_requests', 'preferred_audit_date')) {
                $auditData['preferred_audit_date'] = $data['preferred_audit_date'] ?? null;
            }
            if (Schema::hasColumn('audit_requests', 'preferred_audit_time')) {
                $auditData['preferred_audit_time'] = !empty($data['preferred_audit_time'])
                    ? (string) $data['preferred_audit_time']
                    : null;
            }

            // Log the data being inserted for debugging
            Log::info('Creating audit request', [
                'user_id' => $userId,
                'audit_data' => $auditData
            ]);

            // Create audit request using DB transaction for safety
            try {
                DB::beginTransaction();
                $auditRequest = AuditRequest::create($auditData);
                DB::commit();
            } catch (\Exception $createException) {
                DB::rollBack();
                throw $createException; // Re-throw to be caught by outer catch
            }

            if (!$auditRequest) {
                Log::error('Audit request creation returned null', ['data' => $auditData]);
                return ResponseHelper::error('Failed to create audit request', 500);
            }

            $message = $auditRequest->audit_type === 'commercial'
                ? 'Commercial audit request submitted successfully.'
                : 'Audit request submitted successfully';

            return ResponseHelper::success([
                'id' => $auditRequest->id,
                'audit_type' => $auditRequest->audit_type,
                'audit_subtype' => Schema::hasColumn('audit_requests', 'audit_subtype') ? $auditRequest->audit_subtype : null,
                'customer_type' => $auditRequest->customer_type,
                'product_category' => Schema::hasColumn('audit_requests', 'product_category') ? $auditRequest->product_category : null,
                'company_name' => Schema::hasColumn('audit_requests', 'company_name') ? $auditRequest->company_name : null,
                'status' => $auditRequest->status,
                'property_state' => $auditRequest->property_state,
                'property_address' => $auditRequest->property_address,
                'property_landmark' => $auditRequest->property_landmark,
                'building_type' => Schema::hasColumn('audit_requests', 'building_type') ? $auditRequest->building_type : null,
                'facility_description' => Schema::hasColumn('audit_requests', 'facility_description') ? $auditRequest->facility_description : null,
                'property_floors' => $auditRequest->property_floors,
                'property_rooms' => $auditRequest->property_rooms,
                'contact_name' => $auditRequest->contact_name,
                'contact_phone' => $auditRequest->contact_phone,
                'is_gated_estate' => $auditRequest->is_gated_estate,
                'estate_name' => $auditRequest->estate_name,
                'estate_address' => $auditRequest->estate_address,
                'preferred_audit_date' => Schema::hasColumn('audit_requests', 'preferred_audit_date')
                    ? $auditRequest->preferred_audit_date?->format('Y-m-d')
                    : null,
                'preferred_audit_time' => Schema::hasColumn('audit_requests', 'preferred_audit_time')
                    ? $auditRequest->preferred_audit_time
                    : null,
                'has_property_details' => !empty($auditRequest->property_address), // Indicates if user provided details
                'source' => Schema::hasColumn('audit_requests', 'source') ? $auditRequest->source : null,
                'created_at' => $auditRequest->created_at->toIso8601String(),
            ], $message);

        } catch (ValidationException $e) {
            Log::error('Audit request validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\QueryException $e) {
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            Log::error('Database error submitting audit request: ' . $errorMessage, [
                'error_code' => $errorCode,
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'sql' => $e->getSql() ?? 'N/A',
                'bindings' => $e->getBindings() ?? []
            ]);
            
            // Provide more specific error messages
            if (strpos($errorMessage, 'foreign key constraint') !== false) {
                if (strpos($errorMessage, 'user_id') !== false) {
                    return ResponseHelper::error('Invalid user account. Please log in again.', 400);
                } elseif (strpos($errorMessage, 'order_id') !== false) {
                    return ResponseHelper::error('Invalid order reference.', 400);
                }
                return ResponseHelper::error('Database constraint violation. Please check your data.', 400);
            }
            
            if (strpos($errorMessage, "doesn't exist") !== false || strpos($errorMessage, 'Unknown column') !== false) {
                return ResponseHelper::error('Database schema error. Please contact support.', 500);
            }
            
            return ResponseHelper::error('Database error: ' . $errorMessage, 500);
        } catch (\Exception $e) {
            Log::error('Error submitting audit request: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request_data' => $request->all()
            ]);
            return ResponseHelper::error('Failed to submit audit request: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get audit request status
     * GET /api/audit/request/{id}
     */
    public function getStatus($id)
    {
        try {
            $auditRequest = AuditRequest::where('id', $id)
                ->where('user_id', Auth::id())
                ->with(['order', 'approver:id,first_name,sur_name,email'])
                ->first();

            if (!$auditRequest) {
                return ResponseHelper::error('Audit request not found', 404);
            }

            return ResponseHelper::success([
                'id' => $auditRequest->id,
                'audit_type' => $auditRequest->audit_type,
                'audit_subtype' => Schema::hasColumn('audit_requests', 'audit_subtype') ? $auditRequest->audit_subtype : null,
                'customer_type' => Schema::hasColumn('audit_requests', 'customer_type') ? $auditRequest->customer_type : null,
                'product_category' => Schema::hasColumn('audit_requests', 'product_category') ? $auditRequest->product_category : null,
                'company_name' => Schema::hasColumn('audit_requests', 'company_name') ? $auditRequest->company_name : null,
                'status' => $auditRequest->status,
                'property_state' => $auditRequest->property_state,
                'property_address' => $auditRequest->property_address,
                'property_landmark' => $auditRequest->property_landmark,
                'building_type' => Schema::hasColumn('audit_requests', 'building_type') ? $auditRequest->building_type : null,
                'facility_description' => Schema::hasColumn('audit_requests', 'facility_description') ? $auditRequest->facility_description : null,
                'property_floors' => $auditRequest->property_floors,
                'property_rooms' => $auditRequest->property_rooms,
                'contact_name' => $auditRequest->contact_name,
                'contact_phone' => $auditRequest->contact_phone,
                'is_gated_estate' => $auditRequest->is_gated_estate,
                'estate_name' => $auditRequest->estate_name,
                'estate_address' => $auditRequest->estate_address,
                'preferred_audit_date' => Schema::hasColumn('audit_requests', 'preferred_audit_date')
                    ? $auditRequest->preferred_audit_date?->format('Y-m-d')
                    : null,
                'preferred_audit_time' => Schema::hasColumn('audit_requests', 'preferred_audit_time')
                    ? $auditRequest->preferred_audit_time
                    : null,
                'source' => Schema::hasColumn('audit_requests', 'source') ? $auditRequest->source : null,
                'admin_notes' => $auditRequest->admin_notes,
                'approval_payment_date' => Schema::hasColumn('audit_requests', 'approval_payment_date')
                    ? $auditRequest->approval_payment_date?->format('Y-m-d')
                    : null,
                'approval_payment_time' => Schema::hasColumn('audit_requests', 'approval_payment_time')
                    ? $auditRequest->approval_payment_time
                    : null,
                'approval_payment_amount' => Schema::hasColumn('audit_requests', 'approval_payment_amount')
                    ? ($auditRequest->approval_payment_amount !== null ? (float) $auditRequest->approval_payment_amount : null)
                    : null,
                'approval_payment_account_details' => Schema::hasColumn('audit_requests', 'approval_payment_account_details')
                    ? $auditRequest->approval_payment_account_details
                    : null,
                'customer_has_paid' => Schema::hasColumn('audit_requests', 'customer_has_paid')
                    ? (bool) $auditRequest->customer_has_paid
                    : false,
                'customer_payment_date' => Schema::hasColumn('audit_requests', 'customer_payment_date')
                    ? $auditRequest->customer_payment_date?->format('Y-m-d')
                    : null,
                'customer_payment_time' => Schema::hasColumn('audit_requests', 'customer_payment_time')
                    ? $auditRequest->customer_payment_time
                    : null,
                'customer_payment_receipt_path' => Schema::hasColumn('audit_requests', 'customer_payment_receipt_path')
                    ? $auditRequest->customer_payment_receipt_path
                    : null,
                'customer_payment_receipt_url' => Schema::hasColumn('audit_requests', 'customer_payment_receipt_path') && $auditRequest->customer_payment_receipt_path
                    ? \App\Models\SiteBanner::resolvePublicUrl(request(), $auditRequest->customer_payment_receipt_path)
                    : null,
                'approved_by' => $auditRequest->approver ? [
                    'id' => $auditRequest->approver->id,
                    'name' => $auditRequest->approver->first_name . ' ' . $auditRequest->approver->sur_name,
                    'email' => $auditRequest->approver->email,
                ] : null,
                'approved_at' => $auditRequest->approved_at?->toIso8601String(),
                'order_id' => $auditRequest->order_id,
                'created_at' => $auditRequest->created_at->toIso8601String(),
            ], 'Audit request retrieved successfully');

        } catch (\Exception $e) {
            Log::error('Error fetching audit request: ' . $e->getMessage());
            return ResponseHelper::error('Failed to fetch audit request', 500);
        }
    }

    /**
     * Get all audit requests for the authenticated user
     * GET /api/audit/requests
     */
    public function index()
    {
        try {
            $auditRequests = AuditRequest::where('user_id', Auth::id())
                ->with(['order:id,order_number,total_price,payment_status'])
                ->latest()
                ->get();

            return ResponseHelper::success(
                $auditRequests->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'audit_type' => $request->audit_type,
                        'audit_subtype' => Schema::hasColumn('audit_requests', 'audit_subtype') ? $request->audit_subtype : null,
                        'customer_type' => Schema::hasColumn('audit_requests', 'customer_type') ? $request->customer_type : null,
                        'product_category' => Schema::hasColumn('audit_requests', 'product_category') ? $request->product_category : null,
                        'company_name' => Schema::hasColumn('audit_requests', 'company_name') ? $request->company_name : null,
                        'building_type' => Schema::hasColumn('audit_requests', 'building_type') ? $request->building_type : null,
                        'facility_description' => Schema::hasColumn('audit_requests', 'facility_description') ? $request->facility_description : null,
                        'status' => $request->status,
                        'property_state' => $request->property_state,
                        'property_address' => $request->property_address,
                        'property_landmark' => $request->property_landmark,
                        'property_floors' => $request->property_floors,
                        'property_rooms' => $request->property_rooms,
                        'contact_name' => $request->contact_name,
                        'contact_phone' => $request->contact_phone,
                        'is_gated_estate' => $request->is_gated_estate,
                        'estate_name' => $request->estate_name,
                        'estate_address' => $request->estate_address,
                        'order_id' => $request->order_id,
                        'order_number' => $request->order?->order_number,
                        'preferred_audit_date' => Schema::hasColumn('audit_requests', 'preferred_audit_date')
                            ? $request->preferred_audit_date?->format('Y-m-d')
                            : null,
                        'preferred_audit_time' => Schema::hasColumn('audit_requests', 'preferred_audit_time')
                            ? $request->preferred_audit_time
                            : null,
                        'source' => Schema::hasColumn('audit_requests', 'source') ? $request->source : null,
                        'admin_notes' => $request->admin_notes,
                        'approval_payment_date' => Schema::hasColumn('audit_requests', 'approval_payment_date')
                            ? $request->approval_payment_date?->format('Y-m-d')
                            : null,
                        'approval_payment_time' => Schema::hasColumn('audit_requests', 'approval_payment_time')
                            ? $request->approval_payment_time
                            : null,
                        'approval_payment_amount' => Schema::hasColumn('audit_requests', 'approval_payment_amount')
                            ? ($request->approval_payment_amount !== null ? (float) $request->approval_payment_amount : null)
                            : null,
                        'approval_payment_account_details' => Schema::hasColumn('audit_requests', 'approval_payment_account_details')
                            ? $request->approval_payment_account_details
                            : null,
                        'customer_has_paid' => Schema::hasColumn('audit_requests', 'customer_has_paid')
                            ? (bool) $request->customer_has_paid
                            : false,
                        'customer_payment_date' => Schema::hasColumn('audit_requests', 'customer_payment_date')
                            ? $request->customer_payment_date?->format('Y-m-d')
                            : null,
                        'customer_payment_time' => Schema::hasColumn('audit_requests', 'customer_payment_time')
                            ? $request->customer_payment_time
                            : null,
                        'customer_payment_receipt_path' => Schema::hasColumn('audit_requests', 'customer_payment_receipt_path')
                            ? $request->customer_payment_receipt_path
                            : null,
                        'customer_payment_receipt_url' => Schema::hasColumn('audit_requests', 'customer_payment_receipt_path') && $request->customer_payment_receipt_path
                            ? \App\Models\SiteBanner::resolvePublicUrl(request(), $request->customer_payment_receipt_path)
                            : null,
                        'approved_at' => $request->approved_at?->toIso8601String(),
                        'created_at' => $request->created_at->toIso8601String(),
                        'updated_at' => $request->updated_at->toIso8601String(),
                    ];
                }),
                'Audit requests retrieved successfully'
            );

        } catch (\Exception $e) {
            Log::error('Error fetching audit requests: ' . $e->getMessage());
            return ResponseHelper::error('Failed to fetch audit requests', 500);
        }
    }
}
