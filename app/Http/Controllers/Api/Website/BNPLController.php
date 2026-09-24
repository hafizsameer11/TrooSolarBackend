<?php

namespace App\Http\Controllers\Api\Website;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Support\BundlePricing;
use App\Models\Bundles;
use App\Models\Guarantor;
use App\Models\LoanApplication;
use App\Models\LoanCalculation;
use App\Models\MonoLoanCalculation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\LoanInstallment;
use App\Models\LoanRepayment;
use App\Models\BnplSettings;
use App\Mail\BNPLApplicationSubmittedEmail;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use App\Services\BnplLoanPlanCalculator;
use App\Services\ReferralRewardService;
use App\Services\MonoService;
use App\Services\MonoDirectDebitService;
use App\Models\MonoCreditCheckSession;
use App\Models\UserMonoAccount;
use App\Models\Partner;
use App\Support\BnplFinanceAgreement;
use App\Support\FrontendUrl;

class BNPLController extends Controller
{
    // Minimum loan amount for BNPL (removed - no minimum requirement)
    // private const MIN_LOAN_AMOUNT = 1500000; // ₦1,500,000

    /**
     * POST /api/bnpl/apply
     * Submit BNPL loan application
     */
    public function apply(Request $request)
    {
        try {
            // Normalize request data - handle FormData bracket notation and trim BVN
            $allInput = $request->all();

            // Convert FormData bracket notation to nested arrays
            $personalDetails = [];
            $propertyDetails = [];
            $nextOfKinIn = [];
            $employmentIn = [];
            $businessIn = [];

            foreach ($allInput as $key => $value) {
                // Handle personal_details[field] notation
                if (preg_match('/^personal_details\[(.+)\]$/', $key, $matches)) {
                    $fieldName = $matches[1];
                    $personalDetails[$fieldName] = $value;
                    unset($allInput[$key]);
                }
                // Handle property_details[field] notation
                elseif (preg_match('/^property_details\[(.+)\]$/', $key, $matches)) {
                    $fieldName = $matches[1];
                    $propertyDetails[$fieldName] = $value;
                    unset($allInput[$key]);
                }
                elseif (preg_match('/^next_of_kin\[(.+)\]$/', $key, $matches)) {
                    $nextOfKinIn[$matches[1]] = $value;
                    unset($allInput[$key]);
                }
                elseif (preg_match('/^employment_details\[(.+)\]$/', $key, $matches)) {
                    $employmentIn[$matches[1]] = $value;
                    unset($allInput[$key]);
                }
                elseif (preg_match('/^business_details\[(.+)\]$/', $key, $matches)) {
                    $businessIn[$matches[1]] = $value;
                    unset($allInput[$key]);
                }
            }

            // Merge converted nested arrays back
            if (!empty($personalDetails)) {
                $allInput['personal_details'] = array_merge($allInput['personal_details'] ?? [], $personalDetails);
            }
            if (!empty($propertyDetails)) {
                $allInput['property_details'] = array_merge($allInput['property_details'] ?? [], $propertyDetails);
            }
            if (!empty($nextOfKinIn)) {
                $allInput['next_of_kin'] = array_merge($allInput['next_of_kin'] ?? [], $nextOfKinIn);
            }
            if (!empty($employmentIn)) {
                $allInput['employment_details'] = array_merge($allInput['employment_details'] ?? [], $employmentIn);
            }
            if (!empty($businessIn)) {
                $allInput['business_details'] = array_merge($allInput['business_details'] ?? [], $businessIn);
            }

            // Empty numeric property fields → null (FormData sends "")
            if (isset($allInput['property_details']) && is_array($allInput['property_details'])) {
                foreach (['floors', 'rooms'] as $numKey) {
                    if (array_key_exists($numKey, $allInput['property_details'])
                        && ($allInput['property_details'][$numKey] === '' || $allInput['property_details'][$numKey] === null)) {
                        $allInput['property_details'][$numKey] = null;
                    }
                }
                if (isset($allInput['property_details']['is_gated_estate'])) {
                    $ige = $allInput['property_details']['is_gated_estate'];
                    $allInput['property_details']['is_gated_estate'] = filter_var($ige, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($allInput['property_details']['is_gated_estate'] === null) {
                        $allInput['property_details']['is_gated_estate'] = in_array((string) $ige, ['1', 'true', 'on', 'yes'], true);
                    }
                }
            }

            // Trim BVN if it exists (handle both nested and flat formats)
            if (isset($allInput['personal_details']['bvn'])) {
                $allInput['personal_details']['bvn'] = preg_replace('/\s+/', '', trim((string) $allInput['personal_details']['bvn']));
            } elseif (isset($allInput['bvn'])) {
                $allInput['bvn'] = preg_replace('/\s+/', '', trim((string) $allInput['bvn']));
            }

            // Merge normalized data back into request
            $request->merge($allInput);

            $priorIdRaw = $request->input('prior_application_id');
            $priorApp = null;
            if ($priorIdRaw !== null && $priorIdRaw !== '') {
                $pid = (int) $priorIdRaw;
                if ($pid > 0) {
                    $priorApp = LoanApplication::where('id', $pid)->where('user_id', Auth::id())->first();
                }
            }
            if (($priorIdRaw !== null && $priorIdRaw !== '') && ! $priorApp) {
                return ResponseHelper::error('Invalid prior application for re-apply.', 422);
            }
            $canReusePriorDocs = $priorApp !== null
                && ! empty($priorApp->bank_statement_path)
                && ! empty($priorApp->live_photo_path);

            $creditCheckMethod = (string) ($allInput['credit_check_method'] ?? 'manual');
            $isAutoCreditCheck = $creditCheckMethod === 'auto';

            // Resolve financing option from Settings → Financing Partner list (Active only).
            // Troosolar is a seeded partner (slug=troosolar) and can be activated/deactivated like others.
            $requestedPartnerId = (int) ($allInput['financing_partner_id'] ?? 0);
            $selectedFinancingPartner = $requestedPartnerId > 0 ? Partner::find($requestedPartnerId) : null;
            if ($selectedFinancingPartner && $selectedFinancingPartner->isTroosolar()) {
                $isPartnerFinancingPath = false;
                $allInput['financing_path'] = 'troosolar';
                $request->merge(['financing_path' => 'troosolar']);
            } else {
                $isPartnerFinancingPath = strtolower((string) ($allInput['financing_path'] ?? '')) === 'partner'
                    || ($selectedFinancingPartner && ! $selectedFinancingPartner->isTroosolar());
                if ($isPartnerFinancingPath) {
                    $allInput['financing_path'] = 'partner';
                    $request->merge(['financing_path' => 'partner']);
                }
            }

            if ($isPartnerFinancingPath) {
                $creditCheckMethod = 'partner';
                $isAutoCreditCheck = false;
                $request->merge(['credit_check_method' => 'partner']);
                $allInput['credit_check_method'] = 'partner';
            }

            $settings = BnplSettings::get();
            $allowedDurations = $settings->loan_durations ?? [3, 6, 9, 12];
            // Validate required fields - handle both JSON and FormData formats
            $validationRules = [
                'customer_type' => 'required|in:residential,sme,commercial',
                'product_category' => 'required|string',
                'loan_amount' => 'required|numeric|min:0',
                'repayment_duration' => 'required|integer|in:' . implode(',', $allowedDurations),
                'credit_check_method' => $isPartnerFinancingPath
                    ? 'required|in:partner'
                    : 'required|in:auto,manual',
                'financing_path' => 'required|in:partner,troosolar',
                'financing_partner_id' => 'nullable|integer|exists:partners,id',
                'finance_agreement_accepted' => 'accepted',
                'property_status' => 'nullable|string|in:owned,rented',
                'bank_statement' => $isAutoCreditCheck
                    ? 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240'
                    : ($canReusePriorDocs
                        ? 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240'
                        : 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'),
                'live_photo' => $isAutoCreditCheck
                    ? 'nullable|file|mimes:jpg,jpeg,png|max:5120'
                    : ($canReusePriorDocs
                        ? 'nullable|file|mimes:jpg,jpeg,png|max:5120'
                        : 'required|file|mimes:jpg,jpeg,png|max:5120'),
                'mono_credit_session_id' => $isAutoCreditCheck
                    ? 'required|integer|exists:mono_credit_check_sessions,id'
                    : 'nullable|integer|exists:mono_credit_check_sessions,id',
            ];

            // Handle nested arrays - check if data comes as nested or flat (after normalization)
            $hasNestedPersonal = isset($allInput['personal_details']) && is_array($allInput['personal_details']);

            if ($hasNestedPersonal) {
                // If personal_details is sent as nested array (JSON or FormData with brackets)
                $validationRules['personal_details'] = 'required|array';
                $validationRules['personal_details.full_name'] = 'required|string|max:255';
                $validationRules['personal_details.bvn'] = 'required|string';
                $validationRules['personal_details.phone'] = 'required|string|max:20';
                $validationRules['personal_details.email'] = 'required|email|max:255';
                $validationRules['personal_details.social_media'] = 'required|string|max:255'; // COMPULSORY
            } else {
                // If sent as flat fields
                $validationRules['full_name'] = 'required|string|max:255';
                $validationRules['bvn'] = 'required|string';
                $validationRules['phone'] = 'required|string|max:20';
                $validationRules['email'] = 'required|email|max:255';
                $validationRules['social_media'] = 'required|string|max:255'; // COMPULSORY
            }

            // Normalize property_details bracket notation if present
            if (isset($allInput['property_details[state]'])) {
                if (!isset($allInput['property_details'])) {
                    $allInput['property_details'] = [];
                }
                $allInput['property_details']['state'] = $allInput['property_details[state]'] ?? null;
                $allInput['property_details']['address'] = $allInput['property_details[address]'] ?? null;
                $allInput['property_details']['is_gated_estate'] = $allInput['property_details[is_gated_estate]'] ?? false;
                $allInput['property_details']['landmark'] = $allInput['property_details[landmark]'] ?? null;
                $allInput['property_details']['floors'] = $allInput['property_details[floors]'] ?? null;
                $allInput['property_details']['rooms'] = $allInput['property_details[rooms]'] ?? null;
                $allInput['property_details']['estate_name'] = $allInput['property_details[estate_name]'] ?? null;
                $allInput['property_details']['estate_address'] = $allInput['property_details[estate_address]'] ?? null;
                // Remove bracket notation keys
                foreach ($allInput as $key => $value) {
                    if (strpos($key, 'property_details[') === 0) {
                        unset($allInput[$key]);
                    }
                }
                $request->merge($allInput);
            }

            $hasNestedProperty = isset($allInput['property_details']) && is_array($allInput['property_details']);

            if ($hasNestedProperty) {
                // If property_details is sent as nested array
                $validationRules['property_details'] = 'required|array';
                $validationRules['property_details.state'] = 'required|string|max:100';
                $validationRules['property_details.address'] = 'required|string';
                $validationRules['property_details.is_gated_estate'] = 'required|boolean';
                $validationRules['property_details.landmark'] = 'nullable|string|max:255';
                $validationRules['property_details.floors'] = 'nullable|integer|min:0';
                $validationRules['property_details.rooms'] = 'nullable|integer|min:0';
                // Must be validated so they are included in $data (otherwise Laravel strips them and DB never saves)
                $validationRules['property_details.estate_name'] = 'nullable|string|max:255';
                $validationRules['property_details.estate_address'] = 'nullable|string';
            } else {
                // If sent as flat fields
                $validationRules['property_state'] = 'required|string|max:100';
                $validationRules['property_address'] = 'required|string';
                $validationRules['is_gated_estate'] = 'required|boolean';
                $validationRules['property_landmark'] = 'nullable|string|max:255';
                $validationRules['property_floors'] = 'nullable|integer|min:1';
                $validationRules['property_rooms'] = 'nullable|integer|min:1';
                $validationRules['estate_name'] = 'nullable|string|max:255';
                $validationRules['estate_address'] = 'nullable|string';
            }

            $data = $request->validate(array_merge($validationRules, [
                'prior_application_id' => 'nullable|integer',
                'bundle_ids' => 'nullable|array',
                'bundle_ids.*' => 'integer|exists:bundles,id',
                'product_ids' => 'nullable|array',
                'product_ids.*' => 'integer|exists:products,id',
                'loan_plan_snapshot' => 'nullable|string',
                // Extended Final Application fields (optional at API; UI enforces by customer type)
                'personal_details.bank_account_no' => 'nullable|string|max:64',
                'personal_details.bank_name' => 'nullable|string|max:255',
                'personal_details.gender' => 'nullable|string|max:64',
                'personal_details.date_of_birth' => 'nullable|string|max:32',
                'personal_details.marital_status' => 'nullable|string|max:64',
                'personal_details.occupation' => 'nullable|string|max:255',
                'personal_details.monthly_income' => 'nullable|string|max:64',
                'personal_details.id_type' => 'nullable|string|max:64',
                'personal_details.id_expiry_date' => 'nullable|string|max:32',
                'personal_details.id_no' => 'nullable|string|max:128',
                'next_of_kin' => 'nullable|array',
                'next_of_kin.name' => 'nullable|string|max:255',
                'next_of_kin.phone' => 'nullable|string|max:40',
                'next_of_kin.address' => 'nullable|string',
                'employment_details' => 'nullable|array',
                'employment_details.company_name' => 'nullable|string|max:255',
                'employment_details.company_address' => 'nullable|string',
                'employment_details.employment_duration' => 'nullable|string|max:128',
                'employment_details.staff_id_no' => 'nullable|string|max:128',
                'business_details' => 'nullable|array',
                'business_details.business_name' => 'nullable|string|max:255',
                'business_details.business_address' => 'nullable|string',
                'business_details.business_rc_bn' => 'nullable|string|max:128',
                'business_details.business_bank_account_no' => 'nullable|string|max:64',
                'business_details.business_bank_name' => 'nullable|string|max:255',
                'business_details.annual_turnover' => 'nullable|string|max:64',
                'business_details.avg_monthly_turnover' => 'nullable|string|max:64',
                'business_details.date_of_incorporation' => 'nullable|string|max:32',
                'business_details.business_ownership' => 'nullable|string',
                'business_details.official_email' => 'nullable|email|max:255',
                'business_details.nature_of_business' => 'nullable|string|max:255',
                'property_details.property_status' => 'nullable|string|in:owned,rented',
            ]));

            $financingPath = strtolower((string) ($data['financing_path'] ?? 'troosolar'));
            $financingPartnerId = null;

            if (! empty($data['financing_partner_id'])) {
                $partner = Partner::find((int) $data['financing_partner_id']);
                if (! $partner || ! $partner->isActive()) {
                    return ResponseHelper::error('Selected financing option is not available. Ask admin to activate it under Settings → Financing Partner.', 422);
                }
                $financingPartnerId = (int) $partner->id;
                $financingPath = $partner->isTroosolar() ? 'troosolar' : 'partner';
            } elseif ($financingPath === 'troosolar') {
                $partner = Partner::ensureTroosolarPartner();
                if (! $partner->isActive()) {
                    return ResponseHelper::error('Troosolar financing is not currently active. Ask admin to activate it under Settings → Financing Partner.', 422);
                }
                $financingPartnerId = (int) $partner->id;
            } elseif ($financingPath !== 'partner') {
                return ResponseHelper::error('Please select a financing option.', 422);
            }

            $isPartnerFinancingPath = $financingPath === 'partner';
            $data['financing_path'] = $financingPath;

            if (strtolower((string) ($data['customer_type'] ?? '')) === 'sme') {
                $propStatus = $data['property_status']
                    ?? ($allInput['property_details']['property_status'] ?? null);
                if (! in_array($propStatus, ['owned', 'rented'], true)) {
                    return ResponseHelper::error('Property status (Owned or Rented) is required for SME applications.', 422);
                }
                $data['property_status'] = $propStatus;
            }

            // Get loan amount (minimum validation removed - no minimum requirement)
            $loanAmount = (float) $data['loan_amount'];

            $planSnapshot = null;
            if (! empty($data['loan_plan_snapshot'])) {
                $decoded = json_decode($data['loan_plan_snapshot'], true);
                $planSnapshot = is_array($decoded) ? $decoded : null;
            }

            // Build order_items_snapshot from bundle_ids and product_ids for creating order items when down payment is confirmed
            // (Troosolar + Partner paths both hit this on /bnpl/apply)
            $orderItemsSnapshot = [];
            $bundleIds = $data['bundle_ids'] ?? [];
            $productIds = $data['product_ids'] ?? [];
            foreach ($bundleIds as $bundleId) {
                $bundle = \App\Models\Bundles::find($bundleId);
                if ($bundle) {
                    $unitPrice = BundlePricing::bnplUnitPrice($bundle);
                    $orderItemsSnapshot[] = [
                        'itemable_type' => \App\Models\Bundles::class,
                        'itemable_id' => (int) $bundleId,
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'subtotal' => $unitPrice,
                    ];
                }
            }
            foreach ($productIds as $productId) {
                $product = Product::find($productId);
                if ($product) {
                    $productDiscount = (float) ($product->discount_price ?? 0);
                    $unitPrice = $productDiscount > 0
                        ? $productDiscount
                        : (float) ($product->price ?? 0);
                    $orderItemsSnapshot[] = [
                        'itemable_type' => Product::class,
                        'itemable_id' => (int) $productId,
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'subtotal' => $unitPrice,
                    ];
                }
            }
            
            // Minimum loan amount validation removed - no minimum requirement
            // if ($loanAmount < self::MIN_LOAN_AMOUNT) {
            //     return ResponseHelper::error(
            //         "Your order total does not meet the minimum ₦" . number_format(self::MIN_LOAN_AMOUNT) . " amount required for credit financing. To qualify for Buy Now, Pay Later, please add more items to your cart. Thank you.",
            //         422
            //     );
            // }

            // Extract personal details (handle both formats)
            $personalDetails = $data['personal_details'] ?? [
                'full_name' => $data['full_name'] ?? null,
                'bvn' => $data['bvn'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'social_media' => $data['social_media'] ?? null,
            ];

            // Ensure BVN is clean (should already be trimmed, but double-check)
            if (isset($personalDetails['bvn'])) {
                $personalDetails['bvn'] = preg_replace('/\s+/', '', trim($personalDetails['bvn']));
                // if (strlen($personalDetails['bvn']) !== 11 || !preg_match('/^\d{11}$/', $personalDetails['bvn'])) {
                //     return ResponseHelper::error('BVN must be exactly 11 digits (numbers only)', 422);
                // }
            }

            // Extract property details (handle both formats)
            $propertyDetails = $data['property_details'] ?? [
                'state' => $data['property_state'] ?? null,
                'address' => $data['property_address'] ?? null,
                'landmark' => $data['property_landmark'] ?? null,
                'floors' => $data['property_floors'] ?? null,
                'rooms' => $data['property_rooms'] ?? null,
                'is_gated_estate' => $data['is_gated_estate'] ?? false,
                'estate_name' => $data['estate_name'] ?? null,
                'estate_address' => $data['estate_address'] ?? null,
            ];

            // Validate gated estate fields if is_gated_estate is true
            if (($propertyDetails['is_gated_estate'] ?? false) == true) {
                $request->validate([
                    'property_details.estate_name' => 'required_with:property_details|string|max:255',
                    'property_details.estate_address' => 'required_with:property_details|string',
                    'estate_name' => 'required_without:property_details|string|max:255',
                    'estate_address' => 'required_without:property_details|string',
                ]);

                if (isset($propertyDetails['estate_name']) && empty($propertyDetails['estate_name'])) {
                    return ResponseHelper::error('Estate name is required when gated estate is selected', 422);
                }
                if (isset($propertyDetails['estate_address']) && empty($propertyDetails['estate_address'])) {
                    return ResponseHelper::error('Estate address is required when gated estate is selected', 422);
                }
            }

            $monoSession = null;
            if ($isAutoCreditCheck) {
                $monoSession = MonoCreditCheckSession::where('id', $data['mono_credit_session_id'])
                    ->where('user_id', Auth::id())
                    ->first();

                if (! $monoSession) {
                    return ResponseHelper::error('Invalid Mono credit check session.', 422);
                }

                if (! in_array($monoSession->status, ['pending', 'processing', 'completed', 'failed'], true)) {
                    return ResponseHelper::error('Invalid Mono credit check session status.', 422);
                }
            }

            // Handle file uploads
            $bankStatementPath = null;
            $livePhotoPath = null;

            if ($request->hasFile('bank_statement')) {
                $file = $request->file('bank_statement');
                $ext = $file->getClientOriginalExtension();
                $fileName = 'bank_statement_' . time() . '.' . $ext;
                $file->move(public_path('/loan_applications'), $fileName);
                $bankStatementPath = 'loan_applications/' . $fileName;
            }

            if ($request->hasFile('live_photo')) {
                $file = $request->file('live_photo');
                $ext = $file->getClientOriginalExtension();
                $fileName = 'live_photo_' . time() . '.' . $ext;
                $file->move(public_path('/loan_applications'), $fileName);
                $livePhotoPath = 'loan_applications/' . $fileName;
            }

            if ($bankStatementPath === null && $priorApp && ! empty($priorApp->bank_statement_path)) {
                $bankStatementPath = $priorApp->bank_statement_path;
            }
            if ($livePhotoPath === null && $priorApp && ! empty($priorApp->live_photo_path)) {
                $livePhotoPath = $priorApp->live_photo_path;
            }

            if (! $isAutoCreditCheck && (empty($bankStatementPath) || empty($livePhotoPath))) {
                return ResponseHelper::error('Bank statement and live photo are required (upload new files or use a valid re-apply link).', 422);
            }

            // Get or create loan calculation
            $loanCalculation = LoanCalculation::where('user_id', Auth::id())
                ->where('status', 'calculated')
                ->latest()
                ->first();

            // Get or create MonoLoanCalculation if loan calculation exists
            $monoLoanCalculationId = null;
            if ($loanCalculation) {
                $repaymentDuration = (int) ($data['repayment_duration'] ?? $loanCalculation->repayment_duration);
                $principalFinanced = $planSnapshot
                    ? (float) ($planSnapshot['principal'] ?? $planSnapshot['totalLoanAmount'] ?? 0)
                    : 0.0;
                $upfrontWithFees = $planSnapshot
                    ? (float) ($planSnapshot['depositAmount'] ?? 0)
                    : 0.0;
                $bundlePlusFeesTotal = $planSnapshot
                    ? (float) ($planSnapshot['totalAmount'] ?? 0)
                    : 0.0;

                // Check if MonoLoanCalculation exists for this LoanCalculation
                $monoLoanCalculation = MonoLoanCalculation::where('loan_calculation_id', $loanCalculation->id)->first();

                if (! $monoLoanCalculation) {
                    if ($planSnapshot && $principalFinanced > 0 && $upfrontWithFees > 0) {
                        $monoLoanCalculation = MonoLoanCalculation::create([
                            'loan_calculation_id' => $loanCalculation->id,
                            'loan_amount' => $principalFinanced,
                            'repayment_duration' => $repaymentDuration,
                            'down_payment' => $upfrontWithFees,
                            'total_amount' => $bundlePlusFeesTotal > 0 ? $bundlePlusFeesTotal : ($principalFinanced + $upfrontWithFees),
                            'status' => 'pending',
                            'interest_rate' => $planSnapshot['interestRate'] ?? $planSnapshot['interest_rate'] ?? $settings->interest_rate_percentage,
                            'management_fee_percentage' => $settings->management_fee_percentage,
                            'legal_fee_percentage' => $settings->legal_fee_percentage,
                            'insurance_fee_percentage' => $settings->insurance_fee_percentage,
                        ]);
                    } else {
                        $monoLoanCalculation = MonoLoanCalculation::create([
                            'loan_calculation_id' => $loanCalculation->id,
                            'loan_amount' => $loanAmount,
                            'repayment_duration' => $repaymentDuration,
                            'down_payment' => round($loanAmount * ((float) ($settings->min_down_percentage ?? 30) / 100), 2),
                            'total_amount' => $loanAmount,
                            'status' => 'pending',
                            'interest_rate' => $settings->interest_rate_percentage,
                            'management_fee_percentage' => $settings->management_fee_percentage,
                            'legal_fee_percentage' => $settings->legal_fee_percentage,
                            'insurance_fee_percentage' => $settings->insurance_fee_percentage,
                        ]);
                    }
                } elseif ($planSnapshot && $principalFinanced > 0 && $upfrontWithFees > 0) {
                    $monoLoanCalculation->loan_amount = $principalFinanced;
                    $monoLoanCalculation->down_payment = $upfrontWithFees;
                    $monoLoanCalculation->total_amount = $bundlePlusFeesTotal > 0 ? $bundlePlusFeesTotal : ($principalFinanced + $upfrontWithFees);
                    $monoLoanCalculation->repayment_duration = $repaymentDuration;
                    if (isset($planSnapshot['interestRate']) || isset($planSnapshot['interest_rate'])) {
                        $monoLoanCalculation->interest_rate = $planSnapshot['interestRate'] ?? $planSnapshot['interest_rate'];
                    }
                    $monoLoanCalculation->save();
                }

                $monoLoanCalculationId = $monoLoanCalculation->id;

                // Update loan calculation status
                $loanCalculation->status = 'submitted';
                $loanCalculation->save();
            }

            // Merge exact "Final Application" fields into loan_plan_snapshot for admin display
            $planSnapshotForDb = is_array($planSnapshot) ? $planSnapshot : [];
            $nextOfKin = $data['next_of_kin'] ?? ($allInput['next_of_kin'] ?? []);
            $employmentDetails = $data['employment_details'] ?? ($allInput['employment_details'] ?? []);
            $businessDetails = $data['business_details'] ?? ($allInput['business_details'] ?? []);
            if (! is_array($nextOfKin)) {
                $nextOfKin = [];
            }
            if (! is_array($employmentDetails)) {
                $employmentDetails = [];
            }
            if (! is_array($businessDetails)) {
                $businessDetails = [];
            }

            $propertyStatus = $data['property_status']
                ?? ($propertyDetails['property_status'] ?? null);

            $planSnapshotForDb['final_application_personal'] = [
                'full_name' => $personalDetails['full_name'] ?? null,
                'bank_account_no' => $personalDetails['bank_account_no'] ?? null,
                'bank_name' => $personalDetails['bank_name'] ?? null,
                'bvn' => $personalDetails['bvn'] ?? null,
                'phone' => $personalDetails['phone'] ?? null,
                'email' => $personalDetails['email'] ?? null,
                'gender' => $personalDetails['gender'] ?? null,
                'date_of_birth' => $personalDetails['date_of_birth'] ?? null,
                'marital_status' => $personalDetails['marital_status'] ?? null,
                'occupation' => $personalDetails['occupation'] ?? null,
                'monthly_income' => $personalDetails['monthly_income'] ?? null,
                'social_media' => $personalDetails['social_media'] ?? null,
                'id_type' => $personalDetails['id_type'] ?? null,
                'id_expiry_date' => $personalDetails['id_expiry_date'] ?? null,
                'id_no' => $personalDetails['id_no'] ?? null,
            ];
            $planSnapshotForDb['final_application_next_of_kin'] = [
                'name' => $nextOfKin['name'] ?? null,
                'phone' => $nextOfKin['phone'] ?? null,
                'address' => $nextOfKin['address'] ?? null,
            ];
            $planSnapshotForDb['final_application_employment'] = [
                'company_name' => $employmentDetails['company_name'] ?? null,
                'company_address' => $employmentDetails['company_address'] ?? null,
                'employment_duration' => $employmentDetails['employment_duration'] ?? null,
                'staff_id_no' => $employmentDetails['staff_id_no'] ?? null,
            ];
            $planSnapshotForDb['final_application_business'] = [
                'business_name' => $businessDetails['business_name'] ?? null,
                'business_address' => $businessDetails['business_address'] ?? null,
                'business_rc_bn' => $businessDetails['business_rc_bn'] ?? null,
                'business_bank_account_no' => $businessDetails['business_bank_account_no'] ?? null,
                'business_bank_name' => $businessDetails['business_bank_name'] ?? null,
                'annual_turnover' => $businessDetails['annual_turnover'] ?? null,
                'avg_monthly_turnover' => $businessDetails['avg_monthly_turnover'] ?? null,
                'date_of_incorporation' => $businessDetails['date_of_incorporation'] ?? null,
                'business_ownership' => $businessDetails['business_ownership'] ?? null,
                'official_email' => $businessDetails['official_email'] ?? null,
                'nature_of_business' => $businessDetails['nature_of_business'] ?? null,
            ];
            $planSnapshotForDb['finance_agreement'] = [
                'accepted' => true,
                'accepted_at' => now()->toIso8601String(),
                'customer_type' => $data['customer_type'] ?? null,
                'text' => BnplFinanceAgreement::forCustomerType($data['customer_type'] ?? null, $settings),
            ];
            $planSnapshotForDb['financing'] = [
                'path' => $financingPath,
                'partner_id' => $financingPartnerId,
                'partner_name' => $financingPartnerId
                    ? (Partner::find($financingPartnerId)?->name)
                    : null,
            ];

            // Create loan application (with optional order_items_snapshot for multi-item BNPL orders)
            $loanApplicationData = [
                'user_id' => Auth::id(),
                'prior_application_id' => $priorApp ? $priorApp->id : null,
                'mono_loan_calculation' => $monoLoanCalculationId, // Can be null if no loan calculation exists
                'loan_amount' => $loanAmount,
                'repayment_duration' => $data['repayment_duration'] ?? null,
                'customer_type' => $data['customer_type'] ?? null,
                'financing_path' => $financingPath,
                'financing_partner_id' => $financingPartnerId,
                'finance_agreement_accepted_at' => now(),
                'product_category' => $data['product_category'] ?? null,
                'audit_type' => $data['audit_type'] ?? null,
                'property_state' => $propertyDetails['state'] ?? null,
                'property_address' => $propertyDetails['address'] ?? null,
                'property_landmark' => $propertyDetails['landmark'] ?? null,
                'property_floors' => $propertyDetails['floors'] ?? null,
                'property_rooms' => $propertyDetails['rooms'] ?? null,
                'property_status' => $propertyStatus,
                'is_gated_estate' => $propertyDetails['is_gated_estate'] ?? false,
                'estate_name' => $propertyDetails['estate_name'] ?? null,
                'estate_address' => $propertyDetails['estate_address'] ?? null,
                'credit_check_method' => $data['credit_check_method'] ?? ($isPartnerFinancingPath ? 'partner' : 'auto'),
                'bank_statement_path' => $bankStatementPath,
                'live_photo_path' => $livePhotoPath,
                'social_media_handle' => $personalDetails['social_media'] ?? null,
                'bvn' => isset($personalDetails['bvn']) && $personalDetails['bvn'] !== ''
                    ? $personalDetails['bvn']
                    : null,
                'status' => 'pending',
                'order_items_snapshot' => !empty($orderItemsSnapshot) ? $orderItemsSnapshot : null,
                'loan_plan_snapshot' => $planSnapshotForDb,
            ];

            if ($monoSession) {
                $loanApplicationData = array_merge($loanApplicationData, [
                    'mono_account_id' => $monoSession->mono_account_id,
                    'mono_customer_id' => $monoSession->mono_customer_id,
                    'mono_credit_status' => $monoSession->status,
                    'mono_can_afford' => $monoSession->can_afford,
                    'mono_monthly_payment_kobo' => $monoSession->monthly_payment_kobo,
                    'mono_credit_report' => $monoSession->credit_worthiness_payload,
                    'mono_credit_session_id' => $monoSession->id,
                ]);
            }

            $loanApplication = LoanApplication::create($loanApplicationData);

            if ($monoSession) {
                $monoSession->update(['loan_application_id' => $loanApplication->id]);
            }

            // Persist BVN on the user profile (form field was validated but not stored before)
            $user = Auth::user();
            if ($user && isset($personalDetails['bvn']) && $personalDetails['bvn'] !== '') {
                $user->bvn = $personalDetails['bvn'];
                $user->save();
            }

            // Send application submitted email (non-blocking for the main flow)
            try {
                $user = Auth::user();
                if ($user && !empty($user->email)) {
                    Mail::to($user->email)->send(new BNPLApplicationSubmittedEmail($user, $loanApplication));
                }
            } catch (\Exception $mailException) {
                Log::warning('BNPL submission email failed: ' . $mailException->getMessage(), [
                    'loan_application_id' => $loanApplication->id ?? null,
                ]);
            }

            return ResponseHelper::success([
                'loan_application' => $loanApplication,
                'message' => \App\Support\MailBrand::BNPL_LABEL.' application submitted successfully. You will receive feedback within 24-72 hours.'
            ], \App\Support\MailBrand::BNPL_LABEL.' application submitted successfully');

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Return 422 for validation errors
            Log::warning('BNPL Application Validation Error', ['errors' => $e->errors()]);
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Application Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to submit BNPL application: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/bnpl/applications
     * Get all BNPL applications for the authenticated user
     */
    public function getApplications(Request $request)
    {
        try {
            $userId = Auth::id();
            
            $query = LoanApplication::with([
                'mono',
                'guarantor:id,loan_application_id,full_name,status',
            ])
            ->where('user_id', $userId);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Sort by latest first
            $applications = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            $formattedData = $applications->getCollection()->map(function ($application) {
                return [
                    'id' => $application->id,
                    'customer_type' => $application->customer_type,
                    'product_category' => $application->product_category,
                    'loan_amount' => number_format((float) $application->loan_amount, 2),
                    'repayment_duration' => $application->repayment_duration,
                    'status' => $application->status, // pending, approved, rejected, counter_offer
                    'financing_path' => $application->financing_path ?: 'troosolar',
                    'property_state' => $application->property_state,
                    'property_address' => $application->property_address,
                    'is_gated_estate' => $application->is_gated_estate,
                    'guarantor' => $application->guarantor ? [
                        'id' => $application->guarantor->id,
                        'full_name' => $application->guarantor->full_name,
                        'status' => $application->guarantor->status,
                    ] : null,
                    'order' => null, // Order relationship will be added if needed
                    'created_at' => $application->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $application->updated_at->format('Y-m-d H:i:s'),
                ];
            });

            return ResponseHelper::success([
                'data' => $formattedData,
                'pagination' => [
                    'current_page' => $applications->currentPage(),
                    'last_page' => $applications->lastPage(),
                    'per_page' => $applications->perPage(),
                    'total' => $applications->total(),
                    'from' => $applications->firstItem(),
                    'to' => $applications->lastItem(),
                ],
            ], 'BNPL applications retrieved successfully');

        } catch (Exception $e) {
            Log::error('BNPL Applications List Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve BNPL applications: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/bnpl/status/{application_id}
     * Get BNPL application status with detailed information
     */
    public function getStatus($applicationId)
    {
        try {
            $application = LoanApplication::with([
                'mono',
                'guarantor:id,loan_application_id,full_name,email,phone,status,signed_form_path',
            ])
            ->where('id', $applicationId)
            ->where('user_id', Auth::id())
            ->first();

            if (!$application) {
                return ResponseHelper::error('Application not found', 404);
            }

            // Prefer stored loan plan snapshot (matches "Review Your Loan Plan" in the dashboard)
            $loanCalculationDetails = $this->buildLoanCalculationForStatus($application);

            // If down payment was done, an order exists for this application's mono – return it so frontend can show order view
            $orderInfo = null;
            if ($application->mono_loan_calculation) {
                $existingOrder = Order::where('mono_calculation_id', $application->mono_loan_calculation)
                    ->where('user_id', Auth::id())
                    ->first();
                if ($existingOrder) {
                    $orderInfo = [
                        'order_id' => $existingOrder->id,
                        'order_number' => $existingOrder->order_number,
                        'down_payment_completed' => true,
                    ];
                }
            }

            // Counter offer: same math as customer "Review Your Loan Plan" (bundle % deposit + admin fees + interest)
            $counterOfferDetails = null;
            if ($application->status === 'counter_offer' &&
                $application->counter_offer_min_deposit !== null &&
                $application->counter_offer_min_tenor !== null) {
                $snap = is_array($application->loan_plan_snapshot) ? $application->loan_plan_snapshot : [];
                $bundle = BnplLoanPlanCalculator::bundlePriceFromSnapshot($snap);
                $feePcts = BnplLoanPlanCalculator::feePercentagesFromSnapshot($snap);
                $monoLoan = $application->mono;
                $interestMonthly = BnplLoanPlanCalculator::interestMonthlyPercentFromSnapshot(
                    $snap,
                    (float) ($monoLoan->interest_rate ?? 4)
                );
                $upfront = (float) $application->counter_offer_min_deposit;
                $duration = (int) $application->counter_offer_min_tenor;
                if ($bundle > 0 && $upfront > 0 && $duration > 0) {
                    $plan = BnplLoanPlanCalculator::computeFromUpfrontTotal(
                        $bundle,
                        $upfront,
                        $duration,
                        $interestMonthly,
                        $feePcts
                    );
                    $counterOfferDetails = [
                        'bundle_price' => number_format($plan['bundle_price'], 2),
                        'loan_amount' => number_format($plan['total_loan_amount'], 2),
                        'down_payment' => number_format($upfront, 2),
                        'upfront_deposit_total' => number_format($plan['upfront_deposit_total'], 2),
                        'admin_fees_total' => number_format($plan['admin_fees_total'], 2),
                        'total_interest_amount' => number_format($plan['total_interest_amount'], 2),
                        'repayment_duration' => $duration,
                        'interest_rate' => $interestMonthly,
                        'total_amount' => number_format($plan['total_repayment_amount'], 2),
                        'total_repayment_amount' => number_format($plan['total_repayment_amount'], 2),
                        'monthly_payment' => number_format($plan['monthly_repayment_amount'], 2),
                    ];
                }
            }

            return ResponseHelper::success([
                'id' => $application->id,
                'customer_type' => $application->customer_type,
                'product_category' => $application->product_category,
                'loan_amount' => number_format((float) $application->loan_amount, 2),
                'repayment_duration' => $application->repayment_duration,
                'status' => $application->status, // pending, approved, rejected, counter_offer, counter_offer_accepted
                'financing_path' => $application->financing_path ?: 'troosolar',
                'financing_partner_id' => $application->financing_partner_id,
                'admin_notes' => $application->admin_notes,
                'counter_offer_min_deposit' => $application->counter_offer_min_deposit !== null ? (float) $application->counter_offer_min_deposit : null,
                'counter_offer_min_tenor' => $application->counter_offer_min_tenor,
                'partner_offer' => [
                    'interest_rate' => $application->partner_offer_interest_rate !== null ? (float) $application->partner_offer_interest_rate : null,
                    'initial_deposit' => $application->partner_offer_initial_deposit !== null ? (float) $application->partner_offer_initial_deposit : null,
                    'admin_fees' => $application->partner_offer_admin_fees !== null ? (float) $application->partner_offer_admin_fees : null,
                    'repayment_amount' => $application->partner_offer_repayment_amount !== null ? (float) $application->partner_offer_repayment_amount : null,
                    'loan_amount' => $application->partner_offer_loan_amount !== null ? (float) $application->partner_offer_loan_amount : null,
                    'tenor' => $application->partner_offer_tenor !== null ? (int) $application->partner_offer_tenor : null,
                    'documents' => is_array($application->partner_offer_documents) ? $application->partner_offer_documents : [],
                ],
                'property_state' => $application->property_state,
                'property_address' => $application->property_address,
                'property_landmark' => $application->property_landmark,
                'property_floors' => $application->property_floors,
                'property_rooms' => $application->property_rooms,
                'is_gated_estate' => $application->is_gated_estate,
                'estate_name' => $application->estate_name,
                'estate_address' => $application->estate_address,
                'credit_check_method' => $application->credit_check_method,
                'mono_account_id' => $application->mono_account_id,
                'mono_credit_status' => $application->mono_credit_status,
                'mono_can_afford' => $application->mono_can_afford,
                'mono_monthly_payment_kobo' => $application->mono_monthly_payment_kobo,
                'mono_credit_report' => $application->mono_credit_report,
                'social_media_handle' => $application->social_media_handle,
                'bank_statement_path' => $application->bank_statement_path,
                'live_photo_path' => $application->live_photo_path,
                'loan_plan_snapshot' => $application->loan_plan_snapshot,
                'loan_calculation' => $loanCalculationDetails,
                'counter_offer_details' => $counterOfferDetails,
                'guarantor' => $application->guarantor ? [
                    'id' => $application->guarantor->id,
                    'full_name' => $application->guarantor->full_name,
                    'email' => $application->guarantor->email,
                    'phone' => $application->guarantor->phone,
                    'status' => $application->guarantor->status,
                    'has_signed_form' => !empty($application->guarantor->signed_form_path),
                    'signed_form_path' => $application->guarantor->signed_form_path,
                ] : null,
                'installation_requested_date' => $this->formatDateValue($application->installation_requested_date, 'Y-m-d'),
                'installation_booking_status' => $application->installation_booking_status,
                'installation_rejected_dates' => $application->installation_rejected_dates ?? [],
                'order_id' => $orderInfo ? $orderInfo['order_id'] : null,
                'order_number' => $orderInfo ? $orderInfo['order_number'] : null,
                'down_payment_completed' => $orderInfo ? $orderInfo['down_payment_completed'] : false,
                'created_at' => $application->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $application->updated_at->format('Y-m-d H:i:s'),
            ], 'Application status retrieved successfully');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('BNPL Status - Application not found: ' . $e->getMessage());
            return ResponseHelper::error('Application not found', 404);
        } catch (Exception $e) {
            Log::error('BNPL Status Error: ' . $e->getMessage(), [
                'application_id' => $applicationId,
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to retrieve application status: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/bnpl/guarantor/form
     * Download the guarantor form PDF (for customers to give to their guarantor).
     * Optional query: loan_application_id — when provided, serves Residential or SME form
     * matching that application's customer_type (commercial → SME form).
     */
    public function downloadGuarantorForm(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $customerType = null;
            $applicationId = $request->query('loan_application_id');
            if ($applicationId) {
                $application = LoanApplication::where('id', $applicationId)
                    ->where('user_id', $user->id)
                    ->first();
                if (!$application) {
                    return response()->json(['message' => 'Loan application not found'], 404);
                }
                $customerType = $application->customer_type;
            } elseif ($request->query('flow')) {
                $customerType = $request->query('flow');
            }

            [$relativePath, $flowKey] = $this->resolveGuarantorFormPath($customerType);
            $fullPath = public_path($relativePath);

            $filename = $flowKey === 'sme'
                ? 'Troosolar-BNPL-Guarantor-Form-SME.pdf'
                : 'Troosolar-BNPL-Guarantor-Form-Residential.pdf';

            // Serve real file only if it exists, is readable, and has content (not empty)
            if (file_exists($fullPath) && is_readable($fullPath) && filesize($fullPath) > 0) {
                $content = file_get_contents($fullPath);
                return response($content, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    'Content-Length' => (string) strlen($content),
                    'Content-Transfer-Encoding' => 'binary',
                    'Cache-Control' => 'no-transform, no-cache',
                    'X-Guarantor-Form-Flow' => $flowKey,
                ]);
            }

            // Fallback: serve placeholder PDF as raw binary (no temp file – avoids proxy/stream issues)
            Log::warning('Guarantor form file not found or empty, serving placeholder', [
                'path' => $fullPath,
                'flow' => $flowKey,
                'customer_type' => $customerType,
            ]);
            $placeholderPdf = $this->getGuarantorFormPlaceholderPdf($flowKey);
            if (strlen($placeholderPdf) === 0) {
                $placeholderPdf = $this->getMinimalPdfFallback();
            }
            return response($placeholderPdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => (string) strlen($placeholderPdf),
                'Content-Transfer-Encoding' => 'binary',
                'Cache-Control' => 'no-transform, no-cache',
                'X-Guarantor-Form-Flow' => $flowKey,
            ]);
        } catch (Exception $e) {
            Log::error('Guarantor form download error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to download guarantor form'], 500);
        }
    }

    /**
     * @return array{0: string, 1: string} [relativePath, flowKey]
     */
    private function resolveGuarantorFormPath(?string $customerType): array
    {
        $type = strtolower(trim((string) $customerType));
        $flowKey = in_array($type, ['sme', 'commercial'], true) ? 'sme' : 'residential';
        $paths = config('bnpl.guarantor_form_paths', []);
        $primary = $flowKey === 'sme'
            ? ($paths['sme'] ?? 'documents/guarantor-form-sme.pdf')
            : ($paths['residential'] ?? 'documents/guarantor-form-residential.pdf');

        $primaryFull = public_path($primary);
        if (is_file($primaryFull) && is_readable($primaryFull) && filesize($primaryFull) > 0) {
            return [$primary, $flowKey];
        }

        // Residential (and unknown) can fall back to the legacy single-form path
        if ($flowKey === 'residential') {
            $legacy = config('bnpl.guarantor_form_path', 'documents/guarantor-form.pdf');
            return [$legacy, $flowKey];
        }

        return [$primary, $flowKey];
    }

    /**
     * Minimal valid PDF used when a flow-specific guarantor form PDF is not present.
     * Text strings use escaped parentheses \( \) so content displays correctly in viewers.
     */
    private function getGuarantorFormPlaceholderPdf(string $flowKey = 'residential'): string
    {
        $label = $flowKey === 'sme' ? 'SME' : 'Residential';
        $hintPath = $flowKey === 'sme'
            ? 'public/documents/guarantor-form-sme.pdf'
            : 'public/documents/guarantor-form-residential.pdf';

        $body = "%PDF-1.4\n";
        $o1 = strlen($body);
        $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $o2 = strlen($body);
        $body .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $o3 = strlen($body);
        $body .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources 4 0 R /MediaBox [0 0 612 792] /Contents 5 0 R >>\nendobj\n";
        $o4 = strlen($body);
        $body .= "4 0 obj\n<< /Font << /F1 6 0 R >> >>\nendobj\n";
        $o5 = strlen($body);
        // PDF text: parentheses in strings must be escaped as \( and \)
        $streamContent = "BT\n"
            . "/F1 18 Tf\n72 720 Td\n"
            . "\(Troosolar BNPL - Guarantor Form - {$label}\) Tj\n"
            . "0 -28 Td\n"
            . "/F1 12 Tf\n"
            . "\(This is a placeholder form.\) Tj\n"
            . "0 -20 Td\n"
            . "/F1 10 Tf\n"
            . "\(Upload the {$label} PDF in Admin - BNPL - Form.\) Tj\n"
            . "0 -16 Td\n"
            . "\({$hintPath}\) Tj\n"
            . "0 -24 Td\n"
            . "\(Signed guarantor documents and undated cheques will be collected on the day of installation.\) Tj\n"
            . "ET\n";
        $body .= "5 0 obj\n<< /Length " . strlen($streamContent) . " >>\nstream\n" . $streamContent . "endstream\nendobj\n";
        $o6 = strlen($body);
        $body .= "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $xref = strlen($body);
        $body .= "xref\n0 7\n";
        $body .= sprintf("%010d 65535 f \n", 0);
        $body .= sprintf("%010d 00000 n \n", $o1);
        $body .= sprintf("%010d 00000 n \n", $o2);
        $body .= sprintf("%010d 00000 n \n", $o3);
        $body .= sprintf("%010d 00000 n \n", $o4);
        $body .= sprintf("%010d 00000 n \n", $o5);
        $body .= sprintf("%010d 00000 n \n", $o6);
        $body .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $body;
    }

    /**
     * Smallest valid PDF (single blank page) used if placeholder generation ever returns empty.
     */
    private function getMinimalPdfFallback(): string
    {
        $b = "%PDF-1.4\n";
        $o1 = strlen($b);
        $b .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $o2 = strlen($b);
        $b .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $o3 = strlen($b);
        $b .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n";
        $xref = strlen($b);
        $b .= "xref\n0 4\n";
        $b .= sprintf("%010d 65535 f \n", 0);
        $b .= sprintf("%010d 00000 n \n", $o1);
        $b .= sprintf("%010d 00000 n \n", $o2);
        $b .= sprintf("%010d 00000 n \n", $o3);
        $b .= "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $b;
    }

    /**
     * POST /api/bnpl/guarantor/invite
     * Invite or add guarantor details
     */
    public function inviteGuarantor(Request $request)
    {
        try {
            $data = $request->validate([
                'loan_application_id' => 'required|exists:loan_applications,id',
                'full_name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'required|string|max:20',
                'bvn' => 'nullable|string|size:11',
                'relationship' => 'nullable|string|max:100',
            ]);

            $application = LoanApplication::where('id', $data['loan_application_id'])
                ->where('user_id', Auth::id())
                ->first();

            if (!$application) {
                return ResponseHelper::error('Loan application not found', 404);
            }

            $guarantor = Guarantor::create([
                'user_id' => Auth::id(),
                'loan_application_id' => $application->id,
                'full_name' => $data['full_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'bvn' => $data['bvn'] ?? null,
                'relationship' => $data['relationship'] ?? null,
                'status' => 'pending',
            ]);

            // Update loan application with guarantor_id
            $application->guarantor_id = $guarantor->id;
            $application->save();

            // TODO: Send email/SMS to guarantor if email/phone provided

            return ResponseHelper::success($guarantor, 'Guarantor details saved successfully');

        } catch (Exception $e) {
            Log::error('Guarantor Invite Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to save guarantor details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/bnpl/guarantor/upload
     * Upload signed guarantor form
     */
    public function uploadGuarantorForm(Request $request)
    {
        try {
            $data = $request->validate([
                'guarantor_id' => 'nullable|exists:guarantors,id',
                'loan_application_id' => 'nullable|exists:loan_applications,id',
                'signed_form' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            ]);

            // Find guarantor: by guarantor_id first, then by loan_application_id
            $guarantor = null;
            if (!empty($data['guarantor_id'])) {
                $guarantor = Guarantor::where('id', $data['guarantor_id'])
                    ->where('user_id', Auth::id())
                    ->first();
            }
            if (!$guarantor && !empty($data['loan_application_id'])) {
                $guarantor = Guarantor::where('loan_application_id', $data['loan_application_id'])
                    ->where('user_id', Auth::id())
                    ->first();
            }

            // If no guarantor record exists yet, create a placeholder so the signed form can be stored
            if (!$guarantor && !empty($data['loan_application_id'])) {
                $loanApp = LoanApplication::where('id', $data['loan_application_id'])
                    ->where('user_id', Auth::id())
                    ->first();
                if (!$loanApp) {
                    return ResponseHelper::error('Loan application not found', 404);
                }
                $user = Auth::user();
                $guarantor = Guarantor::create([
                    'user_id' => Auth::id(),
                    'loan_application_id' => $data['loan_application_id'],
                    'full_name' => $user->first_name . ' ' . ($user->last_name ?? ''),
                    'email' => $user->email,
                    'phone' => $user->phone ?? '',
                    'status' => 'pending',
                ]);
            }

            if (!$guarantor) {
                return ResponseHelper::error('Could not process upload. Please provide a loan application ID.', 400);
            }

            $file = $request->file('signed_form');
            $ext = $file->getClientOriginalExtension();
            $fileName = 'guarantor_form_' . $guarantor->id . '_' . time() . '.' . $ext;
            $file->move(public_path('/loan_applications'), $fileName);
            $filePath = 'loan_applications/' . $fileName;

            $guarantor->signed_form_path = $filePath;
            $guarantor->save();

            return ResponseHelper::success([
                'guarantor_id' => $guarantor->id,
                'signed_form_path' => $filePath,
            ], 'Guarantor form uploaded successfully');

        } catch (Exception $e) {
            Log::error('Guarantor Form Upload Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to upload guarantor form: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/bnpl/counteroffer/accept
     * Accept counteroffer from admin – update Mono + LoanCalculation and set status.
     */
    public function acceptCounterOffer(Request $request)
    {
        try {
            $data = $request->validate([
                'application_id' => 'required|exists:loan_applications,id',
                'minimum_deposit' => 'required|numeric|min:0',
                'minimum_tenor' => 'required|integer|in:3,6,9,12',
            ]);

            $application = LoanApplication::with('mono.loanCalculation')
                ->where('id', $data['application_id'])
                ->where('user_id', Auth::id())
                ->first();

            if (!$application) {
                return ResponseHelper::error('Application not found', 404);
            }

            if ($application->status !== 'counter_offer') {
                return ResponseHelper::error('This application does not have a counter offer to accept', 422);
            }

            $mono = $application->mono;
            if (!$mono) {
                return ResponseHelper::error('Loan calculation not found for this application', 404);
            }

            $downPayment = (float) $data['minimum_deposit'];
            $duration = (int) $data['minimum_tenor'];
            $snap = is_array($application->loan_plan_snapshot) ? $application->loan_plan_snapshot : [];
            $bundle = BnplLoanPlanCalculator::bundlePriceFromSnapshot($snap);
            if ($bundle <= 0) {
                return ResponseHelper::error(
                    'This application is missing loan plan (bundle) data. Contact support or re-apply from the BNPL flow.',
                    422
                );
            }
            $feePcts = BnplLoanPlanCalculator::feePercentagesFromSnapshot($snap);
            $interestMonthly = BnplLoanPlanCalculator::interestMonthlyPercentFromSnapshot(
                $snap,
                (float) ($mono->interest_rate ?? 4)
            );
            $plan = BnplLoanPlanCalculator::computeFromUpfrontTotal(
                $bundle,
                $downPayment,
                $duration,
                $interestMonthly,
                $feePcts
            );
            if ((float) $plan['base_deposit'] <= 0 || (float) $plan['base_loan_amount'] <= 0) {
                return ResponseHelper::error(
                    'The counter-offer upfront amount is too low for this bundle and fee structure. Increase the minimum deposit percentage in admin and try again.',
                    422
                );
            }
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
            $application->status = 'counter_offer_accepted';
            $application->save();

            if ($mono->loanCalculation) {
                $mono->loanCalculation->loan_amount = $principal;
                $mono->loanCalculation->repayment_duration = $duration;
                $mono->loanCalculation->monthly_payment = $monthlyPayment;
                $mono->loanCalculation->repayment_date = $mono->loanCalculation->repayment_date ?? now()->addMonth();
                $mono->loanCalculation->save();
            }

            return ResponseHelper::success([
                'application_id' => $application->id,
                'minimum_deposit' => $downPayment,
                'minimum_tenor' => $duration,
                'down_payment' => $downPayment,
                'repayment_duration' => $duration,
                'total_amount' => $mono->total_amount,
                'monthly_payment' => $monthlyPayment,
                'principal' => $principal,
                'total_repayment' => $totalRepayment,
                'message' => 'Counter offer accepted. Please pay your initial down payment to complete the order.',
            ], 'Counter offer accepted successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('Counteroffer Accept Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to accept counter offer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/bnpl/applications/{id}/confirm-down-payment
     * After Flutterwave success: create BNPL order and installments, complete application flow.
     */
    public function confirmDownPayment(Request $request, $id)
    {
        try {
            $request->validate([
                'transaction_reference' => 'nullable|max:255', // accept string or number (Flutterwave may return numeric id)
                'amount_paid' => 'nullable|numeric|min:0',
                'delivery_address_id' => 'nullable|exists:delivery_addresses,id',
            ]);

            $application = LoanApplication::with('mono.loanCalculation')->where('id', $id)
                ->where('user_id', Auth::id())
                ->first();

            if (!$application) {
                return ResponseHelper::error('Application not found', 404);
            }

            $status = $application->status;
            if (!in_array($status, ['approved', 'counter_offer_accepted'], true)) {
                return ResponseHelper::error('Application must be approved or counter offer accepted before paying down payment', 422);
            }

            $mono = $application->mono;
            if (!$mono) {
                return ResponseHelper::error('Loan calculation not found for this application', 404);
            }

            // Check if order already created for this application (idempotent)
            $existingOrder = \App\Models\Order::where('mono_calculation_id', $mono->id)
                ->where('user_id', Auth::id())
                ->where(function ($q) {
                    $q->where('order_type', 'bnpl')->orWhereNull('order_type');
                })
                ->first();

            if ($existingOrder) {
                return ResponseHelper::success([
                    'order_id' => $existingOrder->id,
                    'order_number' => $existingOrder->order_number,
                    'message' => 'Order already created for this application.',
                ], 'Order already exists');
            }

            $calc = $mono->loanCalculation;
            if ($calc) {
                $duration = (int) $mono->repayment_duration;
                $totalAmount = (float) $mono->total_amount;
                $downPayment = (float) $mono->down_payment;
                $monthlyPayment = $duration > 0 ? round(($totalAmount - $downPayment) / $duration, 2) : 0;
                $calc->repayment_duration = $duration;
                $calc->monthly_payment = $monthlyPayment;
                $calc->repayment_date = $calc->repayment_date ?? now()->addMonth();
                $calc->save();
            }

            $deliveryAddressId = $request->input('delivery_address_id');
            if ($deliveryAddressId) {
                $owned = \App\Models\DeliveryAddress::where('id', $deliveryAddressId)
                    ->where('user_id', Auth::id())
                    ->exists();
                if (!$owned) {
                    $deliveryAddressId = null;
                }
            }

            // If no delivery address provided, create one from the application's property address
            if (!$deliveryAddressId && ($application->property_address || $application->property_state)) {
                $user = Auth::user();
                $deliveryAddress = \App\Models\DeliveryAddress::create([
                    'user_id' => Auth::id(),
                    'address' => $application->property_address ?? '',
                    'state' => $application->property_state ?? null,
                    'title' => 'BNPL delivery',
                    'phone_number' => $user->phone ?? null,
                ]);
                $deliveryAddressId = $deliveryAddress->id;
            }

            $order = Order::create([
                'user_id' => Auth::id(),
                'order_number' => strtoupper('BNPL-' . \Illuminate\Support\Str::random(8)),
                'total_price' => (float) $mono->total_amount,
                'payment_status' => 'paid',
                'order_status' => 'pending',
                'payment_method' => 'flutterwave',
                'mono_calculation_id' => $mono->id,
                'order_type' => 'bnpl',
                'delivery_address_id' => $deliveryAddressId,
            ]);

            // Create order items from application snapshot (so order detail shows all bundles/products)
            $snapshot = $application->order_items_snapshot;
            if (!empty($snapshot) && is_array($snapshot)) {
                foreach ($snapshot as $row) {
                    $itemableType = $row['itemable_type'] ?? null;
                    $itemableId = (int) ($row['itemable_id'] ?? 0);
                    $quantity = (int) ($row['quantity'] ?? 1);
                    $unitPrice = (float) ($row['unit_price'] ?? 0);
                    $subtotal = (float) ($row['subtotal'] ?? $unitPrice * $quantity);
                    if ($itemableType && $itemableId > 0) {
                        OrderItem::create([
                            'order_id' => $order->id,
                            'itemable_type' => $itemableType,
                            'itemable_id' => $itemableId,
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'subtotal' => $subtotal,
                        ]);
                    }
                }
            }

            \App\Services\LoanInstallmentScheduler::generate($mono->id, null, false);

            // Update application status to 'approved' after down payment is confirmed
            $application->status = 'approved';
            $application->save();

            // Add remaining loan balance to user's loan wallet
            $remainingBalance = (float) $mono->total_amount - (float) $mono->down_payment;
            if ($remainingBalance > 0) {
                $wallet = \App\Models\Wallet::firstOrCreate(
                    ['user_id' => Auth::id()],
                    ['loan_balance' => 0, 'shop_balance' => 0]
                );
                $currentLoanBalance = (float) ($wallet->loan_balance ?? 0);
                $wallet->loan_balance = $currentLoanBalance + $remainingBalance;
                $wallet->save();
            }

            // Reward referrer when user has successfully paid BNPL down payment.
            app(ReferralRewardService::class)->award(
                Auth::user(),
                (float) ($mono->down_payment ?? 0),
                'bnpl_down_payment',
                $order
            );

            return ResponseHelper::success([
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'message' => 'Order completed. Your BNPL order has been placed successfully.',
            ], 'Down payment confirmed, order created successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Confirm Down Payment Error: ' . $e->getMessage(), [
                'application_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to confirm down payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/bnpl/orders
     * Get all BNPL orders for the authenticated user
     */
    public function getOrders(Request $request)
    {
        try {
            $userId = Auth::id();
            
            $query = Order::with([
                'items.itemable',
                'deliveryAddress',
                'monoCalculation.loanInstallments',
                'monoCalculation.loanRepayments',
            ])
            ->where('user_id', $userId)
            ->where('order_type', 'bnpl');

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('order_status', $request->status);
            }

            // Sort by latest first
            $orders = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            $formattedData = $orders->getCollection()->map(function ($order) {
                return $this->formatBnplOrder($order);
            });

            return ResponseHelper::success([
                'data' => $formattedData,
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
            ], 'BNPL orders retrieved successfully');

        } catch (Exception $e) {
            Log::error('BNPL Orders List Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve BNPL orders: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/bnpl/orders/{order_id}
     * Get single BNPL order with full repayment details
     */
    public function getOrderDetails($orderId)
    {
        try {
            $userId = Auth::id();
            
            $order = Order::with([
                'items.itemable',
                'deliveryAddress',
                'monoCalculation.loanInstallments.transaction',
                'monoCalculation.loanRepayments',
                'monoCalculation.loanCalculation',
            ])
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->where('order_type', 'bnpl')
            ->first();

            if (!$order) {
                return ResponseHelper::error('BNPL order not found', 404);
            }

            // Get loan application linked to this order
            $loanApplication = null;
            if ($order->mono_calculation_id) {
                $loanApplication = LoanApplication::with(['guarantor', 'mono'])
                    ->where('mono_loan_calculation', $order->mono_calculation_id)
                    ->where('user_id', $userId)
                    ->first();
            }

            // Format installments with repayment schedule
            $installments = [];
            $repaymentSchedule = [];
            if ($order->monoCalculation) {
                $allInstallments = $order->monoCalculation->loanInstallments()
                    ->orderBy('payment_date', 'asc')
                    ->get();
                
                foreach ($allInstallments as $installment) {
                    $paymentDate = $this->toCarbon($installment->payment_date);
                    $paidAt = $this->toCarbon($installment->paid_at);
                    $transactedAt = $installment->transaction
                        ? $this->toCarbon($installment->transaction->transacted_at)
                        : null;
                    $installmentData = [
                        'id' => $installment->id,
                        'installment_number' => $installment->installment_number ?? null,
                        'amount' => (float) $installment->amount,
                        'payment_date' => $paymentDate ? $paymentDate->format('Y-m-d') : null,
                        'status' => $installment->status,
                        'paid_at' => $paidAt ? $paidAt->format('Y-m-d H:i:s') : null,
                        'is_overdue' => $paymentDate && $paymentDate->lt(now()) && $installment->status !== 'paid',
                        'transaction' => $installment->transaction ? [
                            'id' => $installment->transaction->id,
                            'tx_id' => $installment->transaction->tx_id,
                            'method' => $installment->transaction->method,
                            'amount' => (float) $installment->transaction->amount,
                            'transacted_at' => $transactedAt ? $transactedAt->format('Y-m-d H:i:s') : null,
                        ] : null,
                    ];
                    $installments[] = $installmentData;
                    $repaymentSchedule[] = $installmentData;
                }
            }

            // Get repayment history
            $repayments = [];
            if ($order->monoCalculation) {
                $repaymentRecords = $order->monoCalculation->loanRepayments()
                    ->orderBy('created_at', 'desc')
                    ->get();
                
                foreach ($repaymentRecords as $repayment) {
                    $repayments[] = [
                        'id' => $repayment->id,
                        'amount' => (float) $repayment->amount,
                        'status' => $repayment->status,
                        'created_at' => $repayment->created_at->format('Y-m-d H:i:s'),
                    ];
                }
            }

            // Calculate summary
            $totalInstallments = count($installments);
            $paidInstallments = count(array_filter($installments, fn($i) => $i['status'] === 'paid'));
            $pendingInstallments = count(array_filter($installments, fn($i) => $i['status'] !== 'paid'));
            $overdueInstallments = count(array_filter($installments, fn($i) => $i['is_overdue'] === true));
            $totalAmount = array_sum(array_column($installments, 'amount'));
            $paidAmount = array_sum(array_column(array_filter($installments, fn($i) => $i['status'] === 'paid'), 'amount'));
            $pendingAmount = $totalAmount - $paidAmount;

            $orderData = $this->formatBnplOrder($order);
            // For BNPL orders without a linked delivery address, use application's property address
            if (!$orderData['delivery_address'] && $loanApplication) {
                $user = Auth::user();
                $orderData['delivery_address'] = (object) [
                    'address' => $loanApplication->property_address ?? '',
                    'state' => $loanApplication->property_state ?? null,
                    'title' => 'BNPL delivery',
                    'phone_number' => $user ? $user->phone : null,
                ];
            }
            $orderData['loan_application'] = $loanApplication ? [
                'id' => $loanApplication->id,
                'status' => $loanApplication->status,
                'loan_amount' => (float) $loanApplication->loan_amount,
                'repayment_duration' => $loanApplication->repayment_duration,
                'property_address' => $loanApplication->property_address,
                'property_state' => $loanApplication->property_state,
                'loan_plan_snapshot' => $loanApplication->loan_plan_snapshot,
                'guarantor' => $loanApplication->guarantor ? [
                    'id' => $loanApplication->guarantor->id,
                    'full_name' => $loanApplication->guarantor->full_name,
                    'status' => $loanApplication->guarantor->status,
                    'signed_form_path' => $loanApplication->guarantor->signed_form_path,
                    'has_signed_form' => !empty($loanApplication->guarantor->signed_form_path),
                ] : null,
                'installation_requested_date' => $this->formatDateValue($loanApplication->installation_requested_date, 'Y-m-d'),
                'installation_booking_status' => $loanApplication->installation_booking_status,
                'installation_rejected_dates' => $loanApplication->installation_rejected_dates ?? [],
            ] : null;
            $orderData['loan_calculation'] = $loanApplication
                ? $this->buildLoanCalculationForStatus($loanApplication)
                : null;
            $orderData['repayment_schedule'] = $repaymentSchedule;
            $orderData['repayment_summary'] = [
                'total_installments' => $totalInstallments,
                'paid_installments' => $paidInstallments,
                'pending_installments' => $pendingInstallments,
                'overdue_installments' => $overdueInstallments,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'pending_amount' => $pendingAmount,
            ];
            $orderData['repayment_history'] = $repayments;
            $orderData['loan_details'] = $order->monoCalculation ? [
                'loan_amount' => (float) ($order->monoCalculation->loan_amount ?? 0),
                'down_payment' => (float) ($order->monoCalculation->down_payment ?? 0),
                'total_amount' => (float) ($order->monoCalculation->total_amount ?? 0),
                'repayment_duration' => $order->monoCalculation->repayment_duration,
                'interest_rate' => $order->monoCalculation->interest_rate,
            ] : null;

            if ($order->mono_calculation_id) {
                $directDebit = app(MonoDirectDebitService::class);
                $mandate = $directDebit->findMandateForCalculation((int) $order->mono_calculation_id, (int) $userId);
                if ($mandate) {
                    $mandate = $directDebit->syncMandateFromMono($mandate);
                }
                $orderData['mono_calculation_id'] = (int) $order->mono_calculation_id;
                $orderData['mono_debit_mandate'] = $directDebit->formatMandateSummary($mandate);
            }

            return ResponseHelper::success($orderData, 'BNPL order details retrieved successfully');

        } catch (Exception $e) {
            Log::error('BNPL Order Details Error: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to retrieve BNPL order details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/bnpl/applications/{application_id}/repayment-schedule
     * Get repayment schedule for a specific BNPL application
     */
    public function getRepaymentSchedule($applicationId)
    {
        try {
            $userId = Auth::id();
            
            $application = LoanApplication::with(['mono.loanInstallments.transaction'])
                ->where('id', $applicationId)
                ->where('user_id', $userId)
                ->first();

            if (!$application) {
                return ResponseHelper::error('Application not found', 404);
            }

            if (!$application->mono_loan_calculation || !$application->mono) {
                return ResponseHelper::error('Loan calculation not found for this application', 404);
            }

            $installments = $application->mono->loanInstallments()
                ->orderBy('payment_date', 'asc')
                ->get();

            $schedule = [];
            foreach ($installments as $installment) {
                $schedule[] = [
                    'id' => $installment->id,
                    'installment_number' => $installment->installment_number ?? null,
                    'amount' => (float) $installment->amount,
                    'payment_date' => $installment->payment_date ? $installment->payment_date->format('Y-m-d') : null,
                    'status' => $installment->status,
                    'paid_at' => $installment->paid_at ? $installment->paid_at->format('Y-m-d H:i:s') : null,
                    'is_overdue' => $installment->payment_date && $installment->payment_date->lt(now()) && $installment->status !== 'paid',
                    'days_until_due' => $installment->payment_date ? now()->diffInDays($installment->payment_date, false) : null,
                    'transaction' => $installment->transaction ? [
                        'id' => $installment->transaction->id,
                        'tx_id' => $installment->transaction->tx_id,
                        'method' => $installment->transaction->method,
                        'amount' => (float) $installment->transaction->amount,
                        'transacted_at' => $installment->transaction->transacted_at ? $installment->transaction->transacted_at->format('Y-m-d H:i:s') : null,
                    ] : null,
                ];
            }

            // Calculate summary
            $totalInstallments = count($schedule);
            $paidInstallments = count(array_filter($schedule, fn($i) => $i['status'] === 'paid'));
            $pendingInstallments = count(array_filter($schedule, fn($i) => $i['status'] !== 'paid'));
            $overdueInstallments = count(array_filter($schedule, fn($i) => $i['is_overdue'] === true));
            $totalAmount = array_sum(array_column($schedule, 'amount'));
            $paidAmount = array_sum(array_column(array_filter($schedule, fn($i) => $i['status'] === 'paid'), 'amount'));
            $pendingAmount = $totalAmount - $paidAmount;

            return ResponseHelper::success([
                'application_id' => $application->id,
                'loan_amount' => (float) ($application->mono->loan_amount ?? $application->loan_amount),
                'repayment_duration' => $application->mono->repayment_duration ?? $application->repayment_duration,
                'schedule' => $schedule,
                'summary' => [
                    'total_installments' => $totalInstallments,
                    'paid_installments' => $paidInstallments,
                    'pending_installments' => $pendingInstallments,
                    'overdue_installments' => $overdueInstallments,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'pending_amount' => $pendingAmount,
                ],
            ], 'Repayment schedule retrieved successfully');

        } catch (Exception $e) {
            Log::error('Repayment Schedule Error: ' . $e->getMessage(), [
                'application_id' => $applicationId,
                'trace' => $e->getTraceAsString()
            ]);
            return ResponseHelper::error('Failed to retrieve repayment schedule: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/bnpl/installation/book
     * Book installation date. Date must be at least 72 hours from now and not a Sunday.
     * Rejected dates (from admin) cannot be re-selected.
     */
    public function bookInstallationDate(Request $request)
    {
        try {
            $userId = Auth::id();
            $request->validate([
                'order_id' => 'required_without:loan_application_id|nullable|integer|exists:orders,id',
                'loan_application_id' => 'required_without:order_id|nullable|integer|exists:loan_applications,id',
                'requested_date' => 'required|date|date_format:Y-m-d',
            ]);
            $requestedDate = \Carbon\Carbon::parse($request->requested_date)->startOfDay();
            $minDate = now()->addHours(72)->startOfDay();
            if ($requestedDate->lt($minDate)) {
                return ResponseHelper::error('Installation date must be at least 72 hours from now.', 422);
            }
            if ($requestedDate->dayOfWeek === 0) {
                return ResponseHelper::error('Sundays are not available for installation.', 422);
            }
            $loanApplication = null;
            if ($request->loan_application_id) {
                $loanApplication = LoanApplication::where('id', $request->loan_application_id)
                    ->where('user_id', $userId)
                    ->first();
            } else {
                $order = Order::where('id', $request->order_id)
                    ->where('user_id', $userId)
                    ->where('order_type', 'bnpl')
                    ->first();
                if (!$order || !$order->mono_calculation_id) {
                    return ResponseHelper::error('Order not found or not linked to BNPL application.', 404);
                }
                $loanApplication = LoanApplication::where('mono_loan_calculation', $order->mono_calculation_id)
                    ->where('user_id', $userId)
                    ->first();
            }
            if (!$loanApplication) {
                return ResponseHelper::error('Application not found.', 404);
            }
            $rejected = $loanApplication->installation_rejected_dates ?? [];
            if (in_array($request->requested_date, $rejected)) {
                return ResponseHelper::error('This date was previously rejected. Please choose another date.', 422);
            }
            $loanApplication->installation_requested_date = $requestedDate;
            $loanApplication->installation_booking_status = 'pending';
            $loanApplication->save();
            return ResponseHelper::success([
                'installation_requested_date' => $this->formatDateValue($loanApplication->installation_requested_date, 'Y-m-d'),
                'installation_booking_status' => $loanApplication->installation_booking_status,
            ], 'Installation date request submitted. You will be notified once it is confirmed.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Book installation date error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to book installation date.', 500);
        }
    }

    /**
     * Helper method to format BNPL order
     */
    private function formatBnplOrder($order)
    {
        $installmentsCount = 0;
        $paidInstallmentsCount = 0;
        $nextPaymentDate = null;
        $nextPaymentAmount = null;

        if ($order->monoCalculation) {
            $allInstallments = $order->monoCalculation->loanInstallments;
            $installmentsCount = $allInstallments->count();
            $paidInstallmentsCount = $allInstallments->where('status', 'paid')->count();
            
            $nextInstallment = $allInstallments->where('status', '!=', 'paid')
                ->sortBy('payment_date')
                ->first();
            
            if ($nextInstallment) {
                $nextPaymentDate = $nextInstallment->payment_date ? $nextInstallment->payment_date->format('Y-m-d') : null;
                $nextPaymentAmount = (float) $nextInstallment->amount;
            }
        }

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_status' => $order->order_status,
            'payment_status' => $order->payment_status,
            'total_price' => (float) $order->total_price,
            'items' => $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'itemable_type' => strtolower(class_basename($item->itemable_type)),
                    'itemable_id' => $item->itemable_id,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'item' => $item->itemable ? [
                        'id' => $item->itemable->id,
                        'title' => $item->itemable->title ?? null,
                    ] : null,
                ];
            }),
            'delivery_address' => $order->deliveryAddress,
            'loan_summary' => [
                'total_installments' => $installmentsCount,
                'paid_installments' => $paidInstallmentsCount,
                'pending_installments' => $installmentsCount - $paidInstallmentsCount,
                'next_payment_date' => $nextPaymentDate,
                'next_payment_amount' => $nextPaymentAmount,
            ],
            'created_at' => $order->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $order->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    private function toCarbon($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function formatDateValue($value, string $format): ?string
    {
        $date = $this->toCarbon($value);
        return $date ? $date->format($format) : null;
    }

    /**
     * Build loan_calculation payload for GET /bnpl/status — aligned with BNPL flow snapshot when present.
     */
    private function buildLoanCalculationForStatus(LoanApplication $application): ?array
    {
        $snapshot = $application->loan_plan_snapshot;
        if (is_array($snapshot) && ! empty($snapshot)) {
            $principal = (float) ($snapshot['principal'] ?? $snapshot['totalLoanAmount'] ?? 0);
            $upfront = (float) ($snapshot['depositAmount'] ?? 0);
            $totalProduct = (float) ($snapshot['totalAmount'] ?? 0);
            $totalRep = (float) ($snapshot['totalRepayment'] ?? $snapshot['totalRepaymentAmount'] ?? (float) $application->loan_amount);
            $monthly = (float) ($snapshot['monthlyRepayment'] ?? $snapshot['monthlyRepaymentAmount'] ?? 0);
            $tenor = (int) ($snapshot['tenor'] ?? $application->repayment_duration ?? 0);
            $interestRate = (float) ($snapshot['interestRate'] ?? $snapshot['interest_rate'] ?? 0);
            if ($interestRate <= 0 && $application->mono) {
                $interestRate = (float) ($application->mono->interest_rate ?? 0);
            }
            $monoInterestFallback = $application->mono ? $application->mono->interest_rate : null;
            $totalInterestAmt = (float) ($snapshot['totalInterestAmount'] ?? $snapshot['totalInterest'] ?? 0);

            return [
                'loan_amount' => number_format($principal, 2),
                'principal_amount' => number_format($principal, 2),
                'total_repayment' => number_format($totalRep, 2),
                'repayment_duration' => $tenor,
                'down_payment' => number_format($upfront, 2),
                'total_amount' => number_format($totalProduct > 0 ? $totalProduct : ($principal + $upfront), 2),
                'monthly_repayment' => number_format($monthly, 2),
                'interest_rate' => $interestRate > 0 ? number_format($interestRate, 2, '.', '') : $monoInterestFallback,
                'total_interest_amount' => number_format($totalInterestAmt, 2),
                'insurance_fee' => isset($snapshot['insuranceFee']) ? number_format((float) $snapshot['insuranceFee'], 2) : null,
                'management_fee' => isset($snapshot['managementFee']) ? number_format((float) $snapshot['managementFee'], 2) : null,
                'legal_fee' => isset($snapshot['legalFee']) ? number_format((float) $snapshot['legalFee'], 2) : null,
                'admin_fees_total' => isset($snapshot['adminFeesTotal']) ? number_format((float) $snapshot['adminFeesTotal'], 2) : null,
                'deposit_percent' => $snapshot['depositPercent'] ?? null,
                'base_deposit_amount' => isset($snapshot['baseDepositAmount']) ? number_format((float) $snapshot['baseDepositAmount'], 2) : null,
            ];
        }

        if ($application->mono_loan_calculation && $application->mono) {
            $monoLoan = $application->mono;
            $principal = (float) ($monoLoan->loan_amount ?? 0);
            $tenor = (int) ($monoLoan->repayment_duration ?? $application->repayment_duration ?? 0);
            $totalRepFromApp = (float) ($application->loan_amount ?? 0);
            $totalRep = $totalRepFromApp > 0 ? $totalRepFromApp : $principal;
            $monthly = ($tenor > 0 && $totalRep > 0) ? ($totalRep / $tenor) : null;
            $rate = (float) ($monoLoan->interest_rate ?? 0);
            $totalInterest = ($principal > 0 && $rate > 0 && $tenor > 0)
                ? (($rate / 100) * $principal * $tenor)
                : null;

            return [
                'loan_amount' => number_format((float) ($monoLoan->loan_amount ?? $application->loan_amount ?? 0), 2),
                'principal_amount' => number_format($principal, 2),
                'repayment_duration' => $tenor,
                'down_payment' => number_format((float) ($monoLoan->down_payment ?? 0), 2),
                'total_amount' => number_format((float) ($monoLoan->total_amount ?? 0), 2),
                'interest_rate' => $monoLoan->interest_rate ?? null,
                'monthly_repayment' => $monthly !== null ? number_format($monthly, 2) : null,
                'total_repayment' => $totalRep > 0 ? number_format($totalRep, 2) : null,
                'total_interest_amount' => $totalInterest !== null ? number_format($totalInterest, 2) : null,
            ];
        }

        return null;
    }

    /**
     * POST /api/bnpl/process-credit-check
     */
    public function processCreditCheck(Request $request, MonoService $monoService)
    {
        try {
            $data = $request->validate([
                'mono_code' => 'required_without:use_linked_account|nullable|string',
                'use_linked_account' => 'sometimes|boolean',
                'bvn' => 'required|string',
                'loan_amount' => 'required|numeric|min:0',
                'repayment_duration' => 'required|integer|min:1',
                'loan_plan_snapshot' => 'nullable',
            ]);

            $useLinked = filter_var($data['use_linked_account'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $bvn = preg_replace('/\s+/', '', trim((string) $data['bvn']));
            $loanAmount = (float) $data['loan_amount'];
            $principalKobo = (int) round($loanAmount * 100);

            $planSnapshot = null;
            if (! empty($data['loan_plan_snapshot'])) {
                if (is_array($data['loan_plan_snapshot'])) {
                    $planSnapshot = $data['loan_plan_snapshot'];
                } else {
                    $decoded = json_decode((string) $data['loan_plan_snapshot'], true);
                    $planSnapshot = is_array($decoded) ? $decoded : null;
                }
            }

            $interestRate = BnplLoanPlanCalculator::interestMonthlyPercentFromSnapshot($planSnapshot);

            if ($useLinked) {
                $linked = UserMonoAccount::where('user_id', Auth::id())
                    ->where('status', 'linked')
                    ->first();

                if (! $linked || ! $linked->mono_account_id) {
                    return ResponseHelper::error('No linked Mono bank account found. Please connect your bank in Profile settings first.', 422);
                }

                $accountId = $linked->mono_account_id;
            } else {
                if (empty($data['mono_code'])) {
                    return ResponseHelper::error('Mono authorization code is required.', 422);
                }

                $accountId = $monoService->exchangeCode($data['mono_code']);

                UserMonoAccount::updateOrCreate(
                    ['user_id' => Auth::id()],
                    [
                        'mono_account_id' => $accountId,
                        'status' => 'linked',
                        'linked_at' => now(),
                    ]
                );
            }

            $creditParams = [
                'bvn' => $bvn,
                'principal' => $principalKobo,
                'interest_rate' => $interestRate,
                'term' => (int) $data['repayment_duration'],
                'run_credit_check' => $monoService->shouldRunCreditCheck(),
            ];

            $session = MonoCreditCheckSession::create([
                'user_id' => Auth::id(),
                'mono_account_id' => $accountId,
                'bvn' => $bvn,
                'principal_kobo' => $principalKobo,
                'interest_rate' => $interestRate,
                'term_months' => (int) $data['repayment_duration'],
                'run_credit_check' => $creditParams['run_credit_check'],
                'api_request_payload' => $monoService->buildCreditWorthinessRequestAudit($accountId, $creditParams),
                'status' => 'pending',
            ]);

            $initResponse = $monoService->initiateCreditWorthiness($accountId, $creditParams);

            $session->update([
                'status' => 'processing',
                'api_init_response' => $initResponse,
            ]);

            return ResponseHelper::success([
                'session_id' => $session->id,
                'status' => 'processing',
                'mono_account_id' => $accountId,
            ], 'Credit check initiated. Results will arrive via webhook.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL process credit check error: ' . $e->getMessage());

            return ResponseHelper::error('Failed to process credit check: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/bnpl/mono-credit-status/{session_id}
     */
    public function monoCreditStatus(int $sessionId)
    {
        try {
            $session = MonoCreditCheckSession::where('id', $sessionId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            return ResponseHelper::success([
                'session_id' => $session->id,
                'status' => $session->status,
                'mono_account_id' => $session->mono_account_id,
                'can_afford' => $session->can_afford,
                'monthly_payment_kobo' => $session->monthly_payment_kobo,
                'monthly_payment' => $session->monthly_payment_kobo !== null
                    ? number_format($session->monthly_payment_kobo / 100, 2)
                    : null,
                'credit_report' => $session->credit_worthiness_payload,
                'error_message' => $session->error_message,
            ], 'Mono credit check status retrieved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ResponseHelper::error('Credit check session not found', 404);
        } catch (Exception $e) {
            Log::error('BNPL mono credit status error: ' . $e->getMessage());

            return ResponseHelper::error('Failed to retrieve credit check status', 500);
        }
    }

    /**
     * POST /api/bnpl/credit-check-fee/mono/initiate
     * Start Mono DirectPay for the BNPL credit check fee (linked account required).
     */
    public function initiateCreditCheckFeeMonoPay(Request $request, MonoService $monoService)
    {
        try {
            $linked = UserMonoAccount::where('user_id', Auth::id())
                ->where('status', 'linked')
                ->first();

            if (! $linked || ! $linked->mono_account_id) {
                return ResponseHelper::error('Link your bank account with Mono before paying with Mono.', 422);
            }

            $settings = BnplSettings::first();
            $fee = (float) ($settings->credit_check_fee ?? 1000);
            $amountKobo = (int) round($fee * 100);
            if ($amountKobo < 1) {
                return ResponseHelper::error('Credit check fee is not configured.', 422);
            }

            $user = Auth::user();
            $reference = 'bnpl_cc_fee_' . Auth::id() . '_' . time();

            $customer = [
                'email' => (string) ($user->email ?? ''),
                'name' => trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->sur_name ?? ''))),
            ];
            if (! empty($user->phone)) {
                $customer['phone'] = (string) $user->phone;
            }

            $payload = [
                'amount' => $amountKobo,
                'type' => 'onetime-debit',
                'method' => 'account',
                'account' => $linked->mono_account_id,
                'description' => 'TrooSolar BNPL credit check fee',
                'reference' => $reference,
                'redirect_url' => FrontendUrl::base() . '/bnpl?step=10&mono_fee_ref=' . rawurlencode($reference),
                'customer' => $customer,
            ];

            $bvn = preg_replace('/\s+/', '', trim((string) ($user->bvn ?? '')));
            if ($bvn !== '') {
                $payload['customer']['identity'] = [
                    'type' => 'bvn',
                    'number' => $bvn,
                ];
            }

            $init = $monoService->initiateDirectPay($payload);
            $data = is_array($init['data'] ?? null) ? $init['data'] : $init;
            $paymentUrl = $data['payment_link']
                ?? $data['mono_url']
                ?? $data['url']
                ?? $data['link']
                ?? null;

            if (! is_string($paymentUrl) || $paymentUrl === '') {
                return ResponseHelper::error('Mono did not return a payment link. Please pay with Flutterwave or try again.', 502);
            }

            return ResponseHelper::success([
                'payment_url' => $paymentUrl,
                'reference' => $reference,
                'amount' => $fee,
            ], 'Mono payment initiated');
        } catch (Exception $e) {
            Log::error('BNPL credit check fee Mono initiate error: ' . $e->getMessage());

            return ResponseHelper::error('Failed to initiate Mono payment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/bnpl/credit-check-fee/mono/verify
     */
    public function verifyCreditCheckFeeMonoPay(Request $request, MonoService $monoService)
    {
        try {
            $data = $request->validate([
                'reference' => 'required|string',
            ]);

            $reference = (string) $data['reference'];
            if (! str_starts_with($reference, 'bnpl_cc_fee_' . Auth::id() . '_')) {
                return ResponseHelper::error('Invalid payment reference.', 422);
            }

            $verified = $monoService->verifyDirectPay($reference);
            $payload = is_array($verified['data'] ?? null) ? $verified['data'] : $verified;
            $status = strtolower((string) ($payload['status'] ?? $verified['status'] ?? ''));

            $paid = in_array($status, ['successful', 'success', 'completed', 'paid'], true);

            return ResponseHelper::success([
                'paid' => $paid,
                'status' => $status ?: null,
            ], $paid ? 'Payment verified' : 'Payment not completed yet');
        } catch (Exception $e) {
            Log::error('BNPL credit check fee Mono verify error: ' . $e->getMessage());

            return ResponseHelper::error('Failed to verify Mono payment: ' . $e->getMessage(), 500);
        }
    }
}
