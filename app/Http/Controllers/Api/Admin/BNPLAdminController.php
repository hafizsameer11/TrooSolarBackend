<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Mail\BNPLStatusEmail;
use App\Support\MailBrand;
use App\Models\BnplSettings;
use App\Models\Bundles;
use App\Models\Guarantor;
use App\Models\AuditRequest;
use App\Models\LoanApplication;
use App\Models\MonoLoanCalculation;
use App\Models\LoanCalculation;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Services\BnplLoanPlanCalculator;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BNPLAdminController extends Controller
{
    /**
     * Get all BNPL applications
     * GET /api/admin/bnpl/applications
     */
    public function index(Request $request)
    {
        try {
            $query = LoanApplication::with(['user', 'guarantor', 'mono'])
                ->where(function ($q) {
                    // BNPL applications: have customer_type and/or product_category set
                    $q->whereNotNull('customer_type')
                      ->orWhereNotNull('product_category');
                });

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by customer type
            if ($request->has('customer_type')) {
                $query->where('customer_type', $request->customer_type);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->user_id);
            }

            // Search by user name or email
            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('sur_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $applications = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return ResponseHelper::success($applications, 'BNPL applications retrieved successfully');
        } catch (Exception $e) {
            Log::error('BNPL Admin Index Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve BNPL applications', 500);
        }
    }

    /**
     * Get single BNPL application details
     * GET /api/admin/bnpl/applications/{id}
     */
    public function show($id)
    {
        try {
            $application = LoanApplication::with([
                'user',
                'guarantor',
                'mono',
                'mono.loanCalculation',
                'financingPartner',
            ])->find($id);

            if (!$application) {
                return ResponseHelper::error('BNPL application not found', 404);
            }

            $payload = $application->toArray();
            $payload['ordered_items'] = $this->resolveOrderedItemsFromSnapshot($application);
            $payload = $this->mergeLoanApplicationEstateFromLinkedAudit($application, $payload);

            return ResponseHelper::success($payload, 'BNPL application retrieved successfully');
        } catch (Exception $e) {
            Log::error('BNPL Admin Show Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve BNPL application', 500);
        }
    }

    /**
     * Resolve bundle/product titles from order_items_snapshot for admin UI (before or after approval).
     *
     * @return array{lines: array<int, array<string, mixed>>, display: ?string}
     */
    private function resolveOrderedItemsFromSnapshot(LoanApplication $application): array
    {
        $snapshot = $application->order_items_snapshot;
        $lines = [];

        if (is_array($snapshot) && count($snapshot) > 0) {
            foreach ($snapshot as $row) {
                $itemableType = $row['itemable_type'] ?? null;
                $itemableId = isset($row['itemable_id']) ? (int) $row['itemable_id'] : null;
                $qty = (int) ($row['quantity'] ?? 1);
                $title = null;
                $kind = null;

                if ($itemableType && $itemableId && is_string($itemableType) && class_exists($itemableType)) {
                    if (is_a($itemableType, Bundles::class, true)) {
                        $b = Bundles::query()->find($itemableId);
                        $title = $b ? (string) ($b->title ?? $b->name ?? '') : null;
                        $kind = 'bundle';
                    } elseif (is_a($itemableType, Product::class, true)) {
                        $p = Product::query()->find($itemableId);
                        $title = $p ? (string) ($p->title ?? $p->name ?? '') : null;
                        $kind = 'product';
                    }
                }

                if ($title === null || $title === '') {
                    if ($itemableId) {
                        $short = is_string($itemableType) ? class_basename($itemableType) : 'Item';
                        $title = $short.' #'.$itemableId.' (title unavailable)';
                    } else {
                        $title = 'Line item (missing reference)';
                    }
                }

                $lines[] = [
                    'kind' => $kind,
                    'kind_label' => $kind === 'bundle' ? 'Bundle' : ($kind === 'product' ? 'Product' : 'Item'),
                    'id' => $itemableId,
                    'title' => $title,
                    'quantity' => $qty,
                    'unit_price' => isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                    'subtotal' => isset($row['subtotal']) ? (float) $row['subtotal'] : null,
                ];
            }
        }

        $displayParts = [];
        foreach ($lines as $l) {
            $t = (string) ($l['title'] ?? '');
            $q = (int) ($l['quantity'] ?? 1);
            if ($t === '') {
                continue;
            }
            $displayParts[] = $q > 1 ? $t.' (×'.$q.')' : $t;
        }
        $display = count($displayParts) > 0 ? implode(', ', $displayParts) : null;

        return [
            'lines' => $lines,
            'display' => $display,
        ];
    }

    /**
     * When estate fields were not stored on loan_applications, copy from audit_requests linked via BNPL order.
     */
    private function mergeLoanApplicationEstateFromLinkedAudit(LoanApplication $application, array $payload): array
    {
        $needName = empty($payload['estate_name'] ?? null);
        $needAddr = empty($payload['estate_address'] ?? null);
        if (! $needName && ! $needAddr) {
            return $payload;
        }
        if (! $application->mono_loan_calculation) {
            return $payload;
        }
        $order = Order::query()
            ->where('mono_calculation_id', $application->mono_loan_calculation)
            ->whereNotNull('audit_request_id')
            ->orderByDesc('id')
            ->first();
        if (! $order || ! $order->audit_request_id) {
            return $payload;
        }
        $audit = AuditRequest::query()->find($order->audit_request_id);
        if (! $audit) {
            return $payload;
        }
        if ($needName && ! empty($audit->estate_name)) {
            $payload['estate_name'] = $audit->estate_name;
        }
        if ($needAddr && ! empty($audit->estate_address)) {
            $payload['estate_address'] = $audit->estate_address;
        }

        return $payload;
    }

    /**
     * Update BNPL application (assign beneficiary email, name, phone – like loan flow)
     * PUT /api/admin/bnpl/applications/{id}
     */
    public function updateApplication(Request $request, $id)
    {
        try {
            $request->validate([
                'beneficiary_email' => 'nullable|email|max:255',
                'beneficiary_name' => 'nullable|string|max:255',
                'beneficiary_phone' => 'nullable|string|max:20',
                'beneficiary_relationship' => 'nullable|string|max:100',
            ]);

            $application = LoanApplication::find($id);
            if (!$application) {
                return ResponseHelper::error('BNPL application not found', 404);
            }

            if ($request->filled('beneficiary_email')) {
                $application->beneficiary_email = $request->beneficiary_email;
            }
            if ($request->filled('beneficiary_name')) {
                $application->beneficiary_name = $request->beneficiary_name;
            }
            if ($request->filled('beneficiary_phone')) {
                $application->beneficiary_phone = $request->beneficiary_phone;
            }
            if ($request->filled('beneficiary_relationship')) {
                $application->beneficiary_relationship = $request->beneficiary_relationship;
            }
            $application->save();

            return ResponseHelper::success($application->fresh(['user', 'guarantor', 'mono']), 'BNPL application updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Admin Update Application Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to update BNPL application', 500);
        }
    }

    /**
     * Update loan offer (amount, down payment, tenor, interest & fees) for BNPL application.
     * Uses global BnplSettings; optional per-application overrides for interest_rate, management_fee, legal_fee, insurance_fee.
     * PUT /api/admin/bnpl/applications/{id}/offer
     */
    public function updateLoanOffer(Request $request, $id)
    {
        try {
            $settings = BnplSettings::get();
            $allowedDurations = $settings->loan_durations ?? [3, 6, 9, 12];

            $request->validate([
                'loan_amount' => 'nullable|numeric|min:0',
                'down_payment' => 'nullable|numeric|min:0',
                'repayment_duration' => 'nullable|integer|in:' . implode(',', $allowedDurations),
                'interest_rate' => 'nullable|numeric|min:0|max:100',
                'management_fee_percentage' => 'nullable|numeric|min:0|max:100',
                'legal_fee_percentage' => 'nullable|numeric|min:0|max:100',
                'insurance_fee_percentage' => 'nullable|numeric|min:0|max:100',
            ]);

            $application = LoanApplication::with('mono.loanCalculation')->find($id);
            if (!$application) {
                return ResponseHelper::error('BNPL application not found', 404);
            }

            $mono = $application->mono;
            if (!$mono) {
                return ResponseHelper::error('No loan calculation linked to this application', 404);
            }

            $loanAmount = $request->filled('loan_amount') ? (float) $request->loan_amount : (float) $mono->loan_amount;
            $downPayment = $request->filled('down_payment') ? (float) $request->down_payment : (float) $mono->down_payment;
            $duration = $request->filled('repayment_duration') ? (int) $request->repayment_duration : (int) $mono->repayment_duration;

            $interestRate = $request->filled('interest_rate')
                ? (float) $request->interest_rate
                : (float) ($mono->interest_rate ?? $settings->interest_rate_percentage);
            $mgmtFee = $request->filled('management_fee_percentage')
                ? (float) $request->management_fee_percentage
                : (float) ($mono->management_fee_percentage ?? $settings->management_fee_percentage);
            $legalFee = $request->filled('legal_fee_percentage')
                ? (float) $request->legal_fee_percentage
                : (float) ($mono->legal_fee_percentage ?? $settings->legal_fee_percentage);
            $insFee = $request->filled('insurance_fee_percentage')
                ? (float) $request->insurance_fee_percentage
                : (float) ($mono->insurance_fee_percentage ?? $settings->insurance_fee_percentage);

            $totalAmount = $loanAmount + $downPayment;
            $totalLoanAmount = $loanAmount;
            $totalInterestAmount = round($totalLoanAmount * ($interestRate / 100), 2);
            $feePercent = $mgmtFee + $legalFee + $insFee;
            $totalFeeAmount = round($totalLoanAmount * ($feePercent / 100), 2);
            $totalRepaymentAmount = $totalLoanAmount + $totalInterestAmount + $totalFeeAmount;
            $monthlyPayment = $duration > 0 ? round($totalRepaymentAmount / $duration, 2) : 0;

            $mono->loan_amount = $loanAmount;
            $mono->down_payment = $downPayment;
            $mono->repayment_duration = $duration;
            $mono->total_amount = $totalAmount;
            $mono->interest_rate = $interestRate;
            $mono->management_fee_percentage = $mgmtFee;
            $mono->legal_fee_percentage = $legalFee;
            $mono->insurance_fee_percentage = $insFee;
            $mono->save();

            $application->loan_amount = $loanAmount;
            $application->repayment_duration = $duration;
            $application->save();

            if ($mono->loanCalculation) {
                $mono->loanCalculation->loan_amount = $loanAmount;
                $mono->loanCalculation->repayment_duration = $duration;
                $mono->loanCalculation->monthly_payment = $monthlyPayment;
                $mono->loanCalculation->repayment_date = $mono->loanCalculation->repayment_date ?? now()->addMonth();
                $mono->loanCalculation->save();
            }

            return ResponseHelper::success($application->fresh(['user', 'mono', 'mono.loanCalculation']), 'Loan offer updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Admin Update Offer Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to update loan offer', 500);
        }
    }

    /**
     * Update BNPL application status
     * PUT /api/admin/bnpl/applications/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $settings = BnplSettings::get();
            $allowedDurations = $settings->loan_durations ?? [3, 6, 9, 12];
            $request->validate([
                'status' => 'required|in:pending,approved,rejected,counter_offer',
                'counter_offer_min_deposit' => 'required_if:status,counter_offer|numeric|min:0',
                'counter_offer_min_tenor' => 'required_if:status,counter_offer|integer|in:' . implode(',', $allowedDurations),
                'admin_notes' => 'nullable|string|max:1000',
            ]);

            $application = LoanApplication::with(['user', 'mono'])->find($id);
            if (!$application) {
                return ResponseHelper::error('BNPL application not found', 404);
            }

            $application->status = $request->status;
            if ($request->has('admin_notes')) {
                $application->admin_notes = $request->admin_notes;
            }
            $application->save();

            if ($request->status === 'counter_offer') {
                $application->counter_offer_min_deposit = $request->counter_offer_min_deposit;
                $application->counter_offer_min_tenor = $request->counter_offer_min_tenor;
                $application->save();
            }

            // When approving, sync counter offer terms to mono if they exist
            if ($request->status === 'approved' && $application->mono) {
                $mono = $application->mono;
                $hasCounterOfferTerms = $application->counter_offer_min_deposit !== null && 
                                       $application->counter_offer_min_tenor !== null;
                
                if ($hasCounterOfferTerms) {
                    $downPayment = (float) $application->counter_offer_min_deposit;
                    $duration = (int) $application->counter_offer_min_tenor;
                    $snap = is_array($application->loan_plan_snapshot) ? $application->loan_plan_snapshot : [];
                    $bundle = BnplLoanPlanCalculator::bundlePriceFromSnapshot($snap);
                    $feePcts = BnplLoanPlanCalculator::feePercentagesFromSnapshot($snap);
                    $interestMonthly = BnplLoanPlanCalculator::interestMonthlyPercentFromSnapshot(
                        $snap,
                        (float) ($mono->interest_rate ?? 4)
                    );
                    if ($bundle > 0 && $downPayment > 0 && $duration > 0) {
                        $plan = BnplLoanPlanCalculator::computeFromUpfrontTotal(
                            $bundle,
                            $downPayment,
                            $duration,
                            $interestMonthly,
                            $feePcts
                        );
                        $totalRepayment = (float) $plan['total_repayment_amount'];
                        $monthlyPayment = (float) $plan['monthly_repayment_amount'];
                        $principal = (float) $plan['base_loan_amount'];

                        $mono->loan_amount = $principal;
                        $mono->down_payment = $downPayment;
                        $mono->repayment_duration = $duration;
                        $mono->total_amount = round($totalRepayment + $downPayment, 2);
                        $mono->save();

                        $application->loan_amount = $totalRepayment;
                        $application->repayment_duration = $duration;
                        $application->save();

                        if ($mono->loanCalculation) {
                            $mono->loanCalculation->loan_amount = $principal;
                            $mono->loanCalculation->repayment_duration = $duration;
                            $mono->loanCalculation->monthly_payment = $monthlyPayment;
                            $mono->loanCalculation->repayment_date = $mono->loanCalculation->repayment_date ?? now()->addMonth();
                            $mono->loanCalculation->save();
                        }
                    }
                }
            }

            // Notify user when admin sends offer or approves/rejects (in-app + email)
            $userId = $application->user_id;
            $status = $request->status;
            if ($userId && in_array($status, ['approved', 'counter_offer', 'rejected'])) {
                $bnpl = MailBrand::BNPL_LABEL;
                $message = $status === 'approved'
                    ? "Your {$bnpl} application has been approved. Please pay your initial down payment to complete the order."
                    : ($status === 'counter_offer'
                        ? "You have a counter offer on your {$bnpl} application. Please review and accept or decline."
                        : "We cannot process your {$bnpl} application at this time. Thank you for choosing Troosolar.");
                Notification::create([
                    'user_id' => $userId,
                    'message' => $message,
                    'type' => 'bnpl_status',
                ]);

                // Send email to customer so they know their loan status and can continue
                $user = $application->user;
                if ($user && !empty($user->email)) {
                    try {
                        Mail::to($user->email)->send(new BNPLStatusEmail($user, $application, $status));
                    } catch (\Throwable $e) {
                        Log::warning('BNPL status email failed: ' . $e->getMessage(), [
                            'application_id' => $application->id,
                            'user_id' => $userId,
                            'status' => $status,
                        ]);
                    }
                }
            }

            return ResponseHelper::success($application->fresh(['user', 'guarantor', 'mono']), 'BNPL application status updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Admin Update Status Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to update BNPL application status', 500);
        }
    }

    /**
     * Get all guarantors
     * GET /api/admin/bnpl/guarantors
     */
    public function getGuarantors(Request $request)
    {
        try {
            $query = Guarantor::with(['user', 'loanApplication']);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $guarantors = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return ResponseHelper::success($guarantors, 'Guarantors retrieved successfully');
        } catch (Exception $e) {
            Log::error('BNPL Admin Get Guarantors Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve guarantors', 500);
        }
    }

    /**
     * Update guarantor status
     * PUT /api/admin/bnpl/guarantors/{id}/status
     */
    public function updateGuarantorStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:pending,approved,rejected',
                'admin_notes' => 'nullable|string|max:1000',
            ]);

            $guarantor = Guarantor::find($id);
            if (!$guarantor) {
                return ResponseHelper::error('Guarantor not found', 404);
            }

            $guarantor->status = $request->status;
            if ($request->has('admin_notes')) {
                $guarantor->admin_notes = $request->admin_notes;
            }
            $guarantor->save();

            return ResponseHelper::success($guarantor, 'Guarantor status updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Admin Update Guarantor Status Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to update guarantor status', 500);
        }
    }

    /**
     * List BNPL guarantor form upload status (Residential + SME).
     * GET /api/admin/bnpl/guarantor-forms
     */
    public function guarantorFormStatus()
    {
        try {
            return ResponseHelper::success([
                'residential' => $this->guarantorFormMeta('residential'),
                'sme' => $this->guarantorFormMeta('sme'),
            ], 'Guarantor forms status retrieved');
        } catch (Exception $e) {
            Log::error('BNPL Admin Guarantor Form Status Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve guarantor form status', 500);
        }
    }

    /**
     * Upload BNPL guarantor form PDF (admin) for a specific flow.
     * This file is served when users download the guarantor form after loan approval.
     * POST /api/admin/bnpl/guarantor-form
     * FormData: guarantor_form (PDF), flow=residential|sme
     */
    public function uploadGuarantorForm(Request $request)
    {
        try {
            $request->validate([
                'guarantor_form' => 'required|file|mimes:pdf|max:10240',
                'flow' => 'required|in:residential,sme',
            ], [
                'guarantor_form.required' => 'Please select a PDF file.',
                'guarantor_form.mimes' => 'The file must be a PDF.',
                'guarantor_form.max' => 'The file may not be greater than 10MB.',
                'flow.required' => 'Please choose Residential or SME.',
                'flow.in' => 'Flow must be residential or sme.',
            ]);

            $flow = strtolower((string) $request->input('flow'));
            $relativePath = $this->guarantorFormRelativePathForFlow($flow);
            $fullPath = public_path($relativePath);
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $file = $request->file('guarantor_form');
            $file->move($dir, basename($fullPath));

            $label = $flow === 'sme' ? 'SME' : 'Residential';

            return ResponseHelper::success([
                'flow' => $flow,
                'path' => $relativePath,
                'message' => "{$label} guarantor form updated. Matching applicants will download this file.",
            ], "{$label} guarantor form uploaded successfully");
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Admin Upload Guarantor Form Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to upload guarantor form: ' . $e->getMessage(), 500);
        }
    }

    private function guarantorFormRelativePathForFlow(string $flow): string
    {
        $paths = config('bnpl.guarantor_form_paths', []);
        if ($flow === 'sme') {
            return $paths['sme'] ?? 'documents/guarantor-form-sme.pdf';
        }

        return $paths['residential'] ?? 'documents/guarantor-form-residential.pdf';
    }

    private function guarantorFormMeta(string $flow): array
    {
        $relativePath = $this->guarantorFormRelativePathForFlow($flow);
        $fullPath = public_path($relativePath);
        $legacyPath = public_path(config('bnpl.guarantor_form_path', 'documents/guarantor-form.pdf'));

        $uploaded = is_file($fullPath) && is_readable($fullPath) && filesize($fullPath) > 0;
        $usingLegacy = false;
        if (!$uploaded && $flow === 'residential' && is_file($legacyPath) && is_readable($legacyPath) && filesize($legacyPath) > 0) {
            $uploaded = true;
            $usingLegacy = true;
            $relativePath = config('bnpl.guarantor_form_path', 'documents/guarantor-form.pdf');
            $fullPath = $legacyPath;
        }

        return [
            'flow' => $flow,
            'path' => $relativePath,
            'uploaded' => $uploaded,
            'using_legacy' => $usingLegacy,
            'size_bytes' => $uploaded ? filesize($fullPath) : 0,
            'updated_at' => $uploaded ? date('c', filemtime($fullPath)) : null,
        ];
    }

    /**
     * Set or update guarantor for a BNPL application (admin).
     * User will only see download/upload in dashboard; no form for user to add guarantor.
     * POST /api/admin/bnpl/applications/{id}/guarantor
     */
    public function setApplicationGuarantor(Request $request, $id)
    {
        try {
            $request->validate([
                'full_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'email' => 'nullable|email|max:255',
                'relationship' => 'nullable|string|max:100',
            ]);

            $application = LoanApplication::find($id);
            if (!$application) {
                return ResponseHelper::error('BNPL application not found', 404);
            }

            $guarantor = Guarantor::where('loan_application_id', $application->id)->first();
            $data = [
                'full_name' => $request->full_name,
                'phone' => $request->phone,
                'email' => $request->email ?? null,
                'relationship' => $request->relationship ?? null,
            ];

            if ($guarantor) {
                $guarantor->update($data);
            } else {
                $guarantor = Guarantor::create([
                    'user_id' => $application->user_id,
                    'loan_application_id' => $application->id,
                    'full_name' => $data['full_name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                    'relationship' => $data['relationship'],
                    'status' => 'pending',
                ]);
                $application->guarantor_id = $guarantor->id;
                $application->save();
            }

            return ResponseHelper::success($guarantor->fresh(), 'Guarantor saved. User can download the form and upload the signed copy.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Admin Set Guarantor Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to save guarantor: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Accept the requested installation date.
     * PUT /api/admin/bnpl/applications/{id}/installation-date/accept
     */
    public function acceptInstallationDate($id)
    {
        try {
            $application = LoanApplication::find($id);
            if (!$application) {
                return ResponseHelper::error('BNPL application not found', 404);
            }
            if ($application->installation_booking_status !== 'pending' || !$application->installation_requested_date) {
                return ResponseHelper::error('No pending installation date to accept.', 422);
            }
            $application->installation_booking_status = 'accepted';
            $application->save();
            return ResponseHelper::success([
                'installation_requested_date' => $application->installation_requested_date->format('Y-m-d'),
                'installation_booking_status' => $application->installation_booking_status,
            ], 'Installation date accepted.');
        } catch (Exception $e) {
            Log::error('BNPL Admin Accept Installation Date Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to accept installation date.', 500);
        }
    }

    /**
     * Reject the requested installation date. User will be able to book another date (excluding this one).
     * PUT /api/admin/bnpl/applications/{id}/installation-date/reject
     */
    public function rejectInstallationDate($id)
    {
        try {
            $application = LoanApplication::with('user')->find($id);
            if (!$application) {
                return ResponseHelper::error('BNPL application not found', 404);
            }
            if ($application->installation_booking_status !== 'pending' || !$application->installation_requested_date) {
                return ResponseHelper::error('No pending installation date to reject.', 422);
            }
            $rejectedDate = $application->installation_requested_date->format('Y-m-d');
            $rejected = $application->installation_rejected_dates ?? [];
            if (!in_array($rejectedDate, $rejected)) {
                $rejected[] = $rejectedDate;
            }
            $application->installation_booking_status = 'rejected';
            $application->installation_rejected_dates = $rejected;
            $application->save();

            try {
                if ($application->user && $application->user->email) {
                    Mail::to($application->user->email)->send(new BNPLStatusEmail($application->user, $application, 'installation_date_rejected'));
                }
            } catch (\Throwable $e) {
                Log::warning('Could not send installation date rejected email: ' . $e->getMessage());
            }

            return ResponseHelper::success([
                'installation_booking_status' => $application->installation_booking_status,
                'installation_rejected_dates' => $application->installation_rejected_dates,
            ], 'Installation date rejected. User has been notified to book another date.');
        } catch (Exception $e) {
            Log::error('BNPL Admin Reject Installation Date Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to reject installation date.', 500);
        }
    }
}

