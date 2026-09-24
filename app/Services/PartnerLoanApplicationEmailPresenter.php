<?php

namespace App\Services;

use App\Models\Bundles;
use App\Models\LoanApplication;
use App\Models\Product;
use App\Models\User;

/**
 * Builds structured view data for partner loan/BNPL application emails (matches admin BNPL detail layout).
 */
class PartnerLoanApplicationEmailPresenter
{
    private const DEFAULT_INTEREST_RATE = 4.0;

    public function __construct(
        private User $user,
        private LoanApplication $application,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $orderedItems = $this->resolveOrderedItemsFromSnapshot();
        $isManualCredit = $this->isManualCreditCheck();
        $isPartnerFinancing = $this->isPartnerFinancingPath();
        $loanSummary = $this->buildLoanSummary($isPartnerFinancing);

        return [
            'is_bnpl' => $this->hasLoanPlanSnapshot(),
            'is_partner_financing' => $isPartnerFinancing,
            'is_manual_credit_check' => $isManualCredit,
            'hide_item_prices' => $isPartnerFinancing,
            'application' => [
                'id' => $this->application->id,
                'status' => $this->application->status,
                'created_at' => $this->formatApplicationDate($this->application->created_at),
                'loan_amount' => $this->application->loan_amount,
                'loan_amount_formatted' => $this->application->loan_amount
                    ? $this->formatNaira((float) $this->application->loan_amount)
                    : null,
                'repayment_duration' => $this->application->repayment_duration,
                'customer_type' => $this->formatSlug($this->application->customer_type),
                'product_category' => $this->formatSlug($this->application->product_category),
                'audit_type' => $this->application->audit_type,
                'prior_application_id' => $this->application->prior_application_id,
                'financing_path' => $isPartnerFinancing ? 'Partner Financing' : 'Troosolar Financing',
                'property_status' => $this->formatSlug(
                    $this->application->property_status
                        ?? (is_array($this->application->loan_plan_snapshot)
                            ? ($this->application->loan_plan_snapshot['property_status'] ?? null)
                            : null)
                ),
            ],
            'ordered_items' => $orderedItems,
            'customer' => $this->buildCustomerBlock(),
            'next_of_kin' => $this->buildNextOfKinBlock(),
            'employment' => $this->buildEmploymentBlock(),
            'business' => $this->buildBusinessBlock(),
            'property' => $this->buildPropertyBlock(),
            'loan_summary' => $loanSummary,
            // Partner path never uses Mono credit check — hide Mono calculation entirely.
            'show_mono_section' => ! $isPartnerFinancing && ! $isManualCredit && $this->application->mono !== null,
            'mono_summary' => ($isPartnerFinancing || $isManualCredit) ? [] : $this->buildMonoSummary(),
            'credit_check' => $this->buildCreditCheckBlock($isPartnerFinancing),
            'beneficiary' => $this->buildBeneficiaryBlock(),
            'kyc_attachments' => $this->buildKycAttachments($isPartnerFinancing),
            'guarantor' => $this->buildGuarantorBlock(),
            'admin' => [
                'notes' => $this->application->admin_notes,
                'counter_offer_min_deposit' => $this->application->counter_offer_min_deposit,
                'counter_offer_min_tenor' => $this->application->counter_offer_min_tenor,
            ],
        ];
    }

    public function isPartnerFinancingPath(): bool
    {
        $path = strtolower((string) ($this->application->financing_path ?? ''));
        if ($path === 'partner') {
            return true;
        }
        $snap = $this->application->loan_plan_snapshot;
        if (is_array($snap)) {
            $snapPath = strtolower((string) data_get($snap, 'financing.path', ''));
            if ($snapPath === 'partner') {
                return true;
            }
        }

        return strtolower((string) ($this->application->credit_check_method ?? '')) === 'partner';
    }

    public function isManualCreditCheck(): bool
    {
        return strtolower((string) ($this->application->credit_check_method ?? '')) === 'manual';
    }

    public function hasLoanPlanSnapshot(): bool
    {
        $snap = $this->application->loan_plan_snapshot;

        return is_array($snap) && $snap !== [];
    }

    /**
     * @return array{lines: array<int, array<string, mixed>>, display: ?string}
     */
    public function resolveOrderedItemsFromSnapshot(): array
    {
        $snapshot = $this->application->order_items_snapshot;
        $lines = [];

        if (is_array($snapshot) && count($snapshot) > 0) {
            foreach ($snapshot as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $itemableType = $row['itemable_type'] ?? null;
                $itemableId = isset($row['itemable_id']) ? (int) $row['itemable_id'] : null;
                $qty = (int) ($row['quantity'] ?? 1);
                $title = null;
                $kind = null;

                $resolvedClass = $this->resolveItemableClass(is_string($itemableType) ? $itemableType : null);
                if ($resolvedClass && $itemableId) {
                    if (is_a($resolvedClass, Bundles::class, true)) {
                        $b = Bundles::query()->find($itemableId);
                        $title = $b ? (string) ($b->title ?? $b->name ?? '') : null;
                        $kind = 'bundle';
                    } elseif (is_a($resolvedClass, Product::class, true)) {
                        $p = Product::query()->find($itemableId);
                        $title = $p ? (string) ($p->title ?? $p->name ?? '') : null;
                        $kind = 'product';
                    }
                }

                if ($title === null || $title === '') {
                    if ($itemableId) {
                        $short = is_string($itemableType) ? class_basename(str_replace('\\\\', '\\', $itemableType)) : 'Item';
                        $title = $short.' #'.$itemableId;
                    } else {
                        $title = 'Line item';
                    }
                }

                $lines[] = [
                    'kind_label' => $kind === 'bundle' ? 'Bundle' : ($kind === 'product' ? 'Product' : 'Item'),
                    'title' => $title,
                    'quantity' => $qty,
                    'unit_price' => isset($row['unit_price']) ? (float) $row['unit_price'] : null,
                    'unit_price_formatted' => isset($row['unit_price']) && (float) $row['unit_price'] > 0
                        ? $this->formatNaira((float) $row['unit_price'])
                        : null,
                    'subtotal' => isset($row['subtotal']) ? (float) $row['subtotal'] : null,
                    'subtotal_formatted' => isset($row['subtotal']) && (float) $row['subtotal'] > 0
                        ? $this->formatNaira((float) $row['subtotal'])
                        : null,
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
        if ($display === null && $this->application->product_category) {
            $display = 'Category: '.$this->formatSlug($this->application->product_category);
        }

        return [
            'lines' => $lines,
            'display' => $display,
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, tenor: int, tenor_label: string, tenor_num: int, title: string}|null
     */
    public function buildLoanSummary(bool $isPartnerFinancing = false): ?array
    {
        $snap = $this->application->loan_plan_snapshot;
        if (! is_array($snap) || $snap === []) {
            return null;
        }

        $loanCalc = $this->application->mono;
        $loanApp = $this->application;

        $tenor = (int) (
            $snap['tenor']
            ?? $loanApp->repayment_duration
            ?? $loanCalc?->repayment_duration
            ?? 12
        );
        if ($tenor <= 0) {
            $tenor = 12;
        }

        // Partner path: same simple calculator the customer sees (Total / Initial Deposit / Loan Amount + Tenor).
        if ($isPartnerFinancing) {
            $totalAmount = $this->pickNum(
                $snap['totalAmount'] ?? null,
                $snap['grandTotal'] ?? null,
                $snap['invoiceGrandTotal'] ?? null,
                $loanCalc?->total_amount
            );
            $initialDeposit = $this->pickNum(
                $snap['baseDepositAmount'] ?? null,
                $snap['depositAmount'] ?? null,
                $loanCalc?->down_payment
            );
            $totalLoanAmount = $this->pickNum(
                $snap['totalLoanAmount'] ?? null,
                $snap['principal'] ?? null,
                $loanCalc?->principal_amount ?? null
            );
            if ($totalLoanAmount <= 0) {
                $totalLoanAmount = max($totalAmount - $initialDeposit, 0);
            }
            $depositPercent = $this->pickNum($snap['depositPercent'] ?? null);
            if ($depositPercent <= 0 && $totalAmount > 0 && $initialDeposit > 0) {
                $depositPercent = (int) round(($initialDeposit / $totalAmount) * 100);
            }
            $depositLabelPct = $depositPercent > 0 ? ((int) $depositPercent).'%' : '—';

            return [
                'title' => 'Financing summary',
                'rows' => [
                    [
                        'num' => 1,
                        'label' => 'Total Amount',
                        'value' => $totalAmount,
                        'value_formatted' => $this->formatNaira($totalAmount),
                        'bold' => false,
                        'accent' => null,
                    ],
                    [
                        'num' => 2,
                        'label' => $depositLabelPct !== '—'
                            ? 'Initial Deposit ('.$depositLabelPct.')'
                            : 'Initial Deposit',
                        'value' => $initialDeposit,
                        'value_formatted' => $this->formatNaira($initialDeposit),
                        'bold' => false,
                        'accent' => 'red',
                    ],
                    [
                        'num' => 3,
                        'label' => 'Total Loan Amount',
                        'value' => $totalLoanAmount,
                        'value_formatted' => $this->formatNaira($totalLoanAmount),
                        'bold' => true,
                        'accent' => null,
                    ],
                ],
                'tenor' => $tenor,
                'tenor_label' => $tenor === 1 ? '1 month' : $tenor.' months',
                'tenor_num' => 4,
            ];
        }

        $statusLower = strtolower((string) ($loanApp->status ?? ''));
        $isCounterOfferAccepted = $statusLower === 'counter_offer_accepted';
        $acceptedMinDeposit = $this->pickNum(
            $loanApp->counter_offer_min_deposit
        );
        $acceptedMinTenor = (int) $this->pickNum($loanApp->counter_offer_min_tenor);

        $feePcts = BnplLoanPlanCalculator::feePercentagesFromSnapshot($snap);
        $iPct = $feePcts['insurance'] / 100;
        $mPct = $feePcts['management'] / 100;
        $lPct = $feePcts['legal'] / 100;

        $totalAmount = $this->pickNum($snap['totalAmount'] ?? null, $loanCalc?->total_amount);
        $adminFeesTotal = $this->pickNum(
            $snap['adminFeesTotal'] ?? null,
            $this->pickNum($snap['insuranceFee'] ?? null)
                + $this->pickNum($snap['managementFee'] ?? null)
                + $this->pickNum($snap['legalFee'] ?? null)
        );

        $initialDepositWithFees = $isCounterOfferAccepted && $acceptedMinDeposit > 0
            ? $acceptedMinDeposit
            : $this->pickNum($snap['depositAmount'] ?? null, $loanCalc?->down_payment);

        $baseDepositFromSnap = $this->pickNum($snap['baseDepositAmount'] ?? null);
        $principalOrLoan = $this->pickNum($snap['principal'] ?? null, $snap['totalLoanAmount'] ?? null);
        $bundlePriceApprox = $principalOrLoan > 0 && $baseDepositFromSnap >= 0
            ? max($principalOrLoan + $baseDepositFromSnap, 0)
            : ($totalAmount > 0 && $adminFeesTotal >= 0
                ? max($totalAmount - $adminFeesTotal, 0)
                : 0);

        $explicitLoanAmount = $this->pickNum(
            $snap['totalLoanAmount'] ?? null,
            $snap['principal'] ?? null
        );

        if ($explicitLoanAmount <= 0 && $loanCalc !== null) {
            $monoLoanAmt = $this->pickNum($loanCalc->loan_amount);
            $monoTotalAmt = $this->pickNum($loanCalc->total_amount);
            if ($monoLoanAmt > 0) {
                $looksLikeDuplicatePrincipal = $monoTotalAmt > 0 && abs($monoLoanAmt - $monoTotalAmt) < 1;
                $tr = $this->pickNum($snap['totalRepaymentAmount'] ?? null, $snap['totalRepayment'] ?? null);
                $looksLikeTotalRepayment = $tr > 0 && abs($monoLoanAmt - $tr) < 1;
                if (! $looksLikeDuplicatePrincipal && ! $looksLikeTotalRepayment) {
                    $explicitLoanAmount = $monoLoanAmt;
                }
            }
        }

        $totalLoanAmount = $explicitLoanAmount > 0
            ? $explicitLoanAmount
            : max($totalAmount - $initialDepositWithFees, 0);

        $depositPercentRaw = $this->pickNum($snap['depositPercent'] ?? null);
        $depositPercentForLabel = $depositPercentRaw;
        if ($depositPercentForLabel <= 0) {
            $baseDep = $this->pickNum($snap['baseDepositAmount'] ?? null);
            if ($baseDep > 0 && $bundlePriceApprox > 0) {
                $depositPercentForLabel = (int) round(($baseDep / $bundlePriceApprox) * 100);
            } elseif ($initialDepositWithFees > 0 && $bundlePriceApprox > 0) {
                $baseOnly = max($initialDepositWithFees - $adminFeesTotal, 0);
                if ($baseOnly > 0) {
                    $depositPercentForLabel = (int) round(($baseOnly / $bundlePriceApprox) * 100);
                }
            }
        }

        $interestRatePercent = BnplLoanPlanCalculator::interestMonthlyPercentFromSnapshot(
            $snap,
            $loanCalc && $loanCalc->interest_rate
                ? (float) $loanCalc->interest_rate
                : self::DEFAULT_INTEREST_RATE
        );

        if ($isCounterOfferAccepted && $acceptedMinTenor > 0) {
            $tenor = $acceptedMinTenor;
        }

        $totalInterestFromApi = $this->pickNum(
            $snap['totalInterestAmount'] ?? null,
            $snap['totalInterest'] ?? null
        );
        $totalInterestAmount = $totalInterestFromApi > 0
            ? $totalInterestFromApi
            : ($interestRatePercent / 100) * $totalLoanAmount * $tenor;

        $totalRepaymentAmount = $this->pickNum(
            $snap['totalRepaymentAmount'] ?? null,
            $snap['totalRepayment'] ?? null
        );
        if ($totalRepaymentAmount <= 0) {
            $totalRepaymentAmount = $totalLoanAmount + $totalInterestAmount;
        }

        $monthlyRepaymentAmount = $this->pickNum(
            $snap['monthlyRepaymentAmount'] ?? null,
            $snap['monthlyRepayment'] ?? null
        );
        if ($monthlyRepaymentAmount <= 0 && $tenor > 0) {
            $monthlyRepaymentAmount = $totalRepaymentAmount / $tenor;
        }

        if ($isCounterOfferAccepted && $acceptedMinDeposit > 0 && $bundlePriceApprox > 0) {
            $denom = 1 - $mPct - $lPct;
            $baseDeposit = $denom > 0.0001
                ? max(($acceptedMinDeposit - $bundlePriceApprox * ($iPct + $mPct + $lPct)) / $denom, 0)
                : 0;
            $baseLoanAmount = max($bundlePriceApprox - $baseDeposit, 0);
            $totalLoanAmount = $baseLoanAmount;
            $totalInterestAmount = ($interestRatePercent / 100) * $baseLoanAmount * $tenor;
            $totalRepaymentAmount = $baseLoanAmount + $totalInterestAmount;
            $monthlyRepaymentAmount = $tenor > 0 ? $totalRepaymentAmount / $tenor : 0;
            if ($bundlePriceApprox > 0) {
                $depositPercentForLabel = (int) round(($baseDeposit / $bundlePriceApprox) * 100);
            }
        }

        $depositLabelPct = $depositPercentForLabel > 0 ? $depositPercentForLabel.'%' : '—';

        $rows = [
            [
                'num' => 1,
                'label' => 'Initial Deposit ('.$depositLabelPct.') + Total Administrative Fees',
                'value' => $initialDepositWithFees,
                'value_formatted' => $this->formatNaira($initialDepositWithFees),
                'bold' => true,
            ],
            [
                'num' => 2,
                'label' => 'Total Loan Amount',
                'value' => $totalLoanAmount,
                'value_formatted' => $this->formatNaira($totalLoanAmount),
                'bold' => false,
            ],
            [
                'num' => 3,
                'label' => 'Total Interest Amount ('.$interestRatePercent.'% × '.$tenor.' mo)',
                'value' => $totalInterestAmount,
                'value_formatted' => $this->formatNaira($totalInterestAmount),
                'bold' => false,
            ],
            [
                'num' => 4,
                'label' => 'Total Repayment Amount',
                'value' => $totalRepaymentAmount,
                'value_formatted' => $this->formatNaira($totalRepaymentAmount),
                'bold' => false,
            ],
            [
                'num' => 5,
                'label' => 'Monthly Repayment Amount',
                'value' => $monthlyRepaymentAmount,
                'value_formatted' => $this->formatNaira($monthlyRepaymentAmount),
                'bold' => true,
            ],
        ];

        return [
            'title' => 'Loan summary',
            'rows' => $rows,
            'tenor' => $tenor,
            'tenor_label' => $tenor === 1 ? '1 month' : $tenor.' months',
            'tenor_num' => 6,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerBlock(): array
    {
        $snapP = $this->finalApplicationPersonalFromSnapshot();
        $fullName = $snapP['full_name']
            ?? trim(($this->user->first_name ?? '').' '.($this->user->sur_name ?? ''));

        return [
            'full_name' => $fullName ?: null,
            'first_name' => $this->user->first_name,
            'surname' => $this->user->sur_name,
            'email' => $snapP['email'] ?? $this->user->email,
            'phone' => $snapP['phone'] ?? $this->user->phone,
            'bvn' => $snapP['bvn'] ?? $this->application->bvn ?? $this->user->bvn ?? null,
            'social_media' => $snapP['social_media']
                ?? ($this->application->social_media_handle ? trim((string) $this->application->social_media_handle) : null),
            'bank_account_no' => $snapP['bank_account_no'] ?? null,
            'bank_name' => $snapP['bank_name'] ?? null,
            'gender' => $this->formatSlug($snapP['gender'] ?? null),
            'date_of_birth' => $snapP['date_of_birth'] ?? null,
            'marital_status' => $this->formatSlug($snapP['marital_status'] ?? null),
            'occupation' => $snapP['occupation'] ?? null,
            'monthly_income' => $snapP['monthly_income'] ?? null,
            'id_type' => $this->formatSlug($snapP['id_type'] ?? null),
            'id_expiry_date' => $snapP['id_expiry_date'] ?? null,
            'id_no' => $snapP['id_no'] ?? null,
            'from_snapshot' => $snapP['full_name'] !== null || $snapP['email'] !== null,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function finalApplicationPersonalFromSnapshot(): array
    {
        $snap = $this->application->loan_plan_snapshot;
        if (! is_array($snap) || empty($snap['final_application_personal']) || ! is_array($snap['final_application_personal'])) {
            return [
                'full_name' => null,
                'bvn' => null,
                'phone' => null,
                'email' => null,
                'social_media' => null,
                'bank_account_no' => null,
                'bank_name' => null,
                'gender' => null,
                'date_of_birth' => null,
                'marital_status' => null,
                'occupation' => null,
                'monthly_income' => null,
                'id_type' => null,
                'id_expiry_date' => null,
                'id_no' => null,
            ];
        }

        $p = $snap['final_application_personal'];
        $s = fn ($v) => ($v !== null && trim((string) $v) !== '') ? trim((string) $v) : null;

        return [
            'full_name' => $s($p['full_name'] ?? null),
            'bvn' => $s($p['bvn'] ?? null),
            'phone' => $s($p['phone'] ?? null),
            'email' => $s($p['email'] ?? null),
            'social_media' => $s($p['social_media'] ?? null),
            'bank_account_no' => $s($p['bank_account_no'] ?? null),
            'bank_name' => $s($p['bank_name'] ?? null),
            'gender' => $s($p['gender'] ?? null),
            'date_of_birth' => $s($p['date_of_birth'] ?? null),
            'marital_status' => $s($p['marital_status'] ?? null),
            'occupation' => $s($p['occupation'] ?? null),
            'monthly_income' => $s($p['monthly_income'] ?? null),
            'id_type' => $s($p['id_type'] ?? null),
            'id_expiry_date' => $s($p['id_expiry_date'] ?? null),
            'id_no' => $s($p['id_no'] ?? null),
        ];
    }

    /**
     * @return array<string, string|null>|null
     */
    private function buildNextOfKinBlock(): ?array
    {
        $snap = $this->application->loan_plan_snapshot;
        if (! is_array($snap) || empty($snap['final_application_next_of_kin']) || ! is_array($snap['final_application_next_of_kin'])) {
            return null;
        }
        $n = $snap['final_application_next_of_kin'];
        $s = fn ($v) => ($v !== null && trim((string) $v) !== '') ? trim((string) $v) : null;
        $block = [
            'name' => $s($n['name'] ?? null),
            'phone' => $s($n['phone'] ?? null),
            'address' => $s($n['address'] ?? null),
        ];
        if ($block['name'] === null && $block['phone'] === null && $block['address'] === null) {
            return null;
        }

        return $block;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function buildEmploymentBlock(): ?array
    {
        $snap = $this->application->loan_plan_snapshot;
        if (! is_array($snap) || empty($snap['final_application_employment']) || ! is_array($snap['final_application_employment'])) {
            return null;
        }
        $e = $snap['final_application_employment'];
        $s = fn ($v) => ($v !== null && trim((string) $v) !== '') ? trim((string) $v) : null;
        $block = [
            'company_name' => $s($e['company_name'] ?? null),
            'company_address' => $s($e['company_address'] ?? null),
            'employment_duration' => $s($e['employment_duration'] ?? null),
            'staff_id_no' => $s($e['staff_id_no'] ?? null),
        ];
        if (count(array_filter($block)) === 0) {
            return null;
        }

        return $block;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function buildBusinessBlock(): ?array
    {
        $snap = $this->application->loan_plan_snapshot;
        if (! is_array($snap) || empty($snap['final_application_business']) || ! is_array($snap['final_application_business'])) {
            return null;
        }
        $b = $snap['final_application_business'];
        $s = fn ($v) => ($v !== null && trim((string) $v) !== '') ? trim((string) $v) : null;
        $block = [
            'business_name' => $s($b['business_name'] ?? null),
            'business_address' => $s($b['business_address'] ?? null),
            'business_rc_bn' => $s($b['business_rc_bn'] ?? null),
            'business_bank_account_no' => $s($b['business_bank_account_no'] ?? null),
            'business_bank_name' => $s($b['business_bank_name'] ?? null),
            'annual_turnover' => $s($b['annual_turnover'] ?? null),
            'avg_monthly_turnover' => $s($b['avg_monthly_turnover'] ?? null),
            'date_of_incorporation' => $s($b['date_of_incorporation'] ?? null),
            'business_ownership' => $s($b['business_ownership'] ?? null),
            'official_email' => $s($b['official_email'] ?? null),
            'nature_of_business' => $s($b['nature_of_business'] ?? null),
        ];
        if (count(array_filter($block)) === 0) {
            return null;
        }

        return $block;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPropertyBlock(): array
    {
        $currentPowerSources = $this->formatCurrentPowerSources($this->application->property_landmark);

        return [
            'state' => $this->application->property_state,
            'address' => $this->application->property_address,
            'current_power_sources' => $currentPowerSources,
            'landmark' => $currentPowerSources,
            'floors' => $this->application->property_floors,
            'rooms' => $this->application->property_rooms,
            'property_status' => $this->formatSlug($this->application->property_status),
            'is_gated_estate' => (bool) $this->application->is_gated_estate,
            'estate_name' => $this->application->estate_name,
            'estate_address' => $this->application->estate_address,
        ];
    }

    private function formatCurrentPowerSources(?string $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        return ucwords(strtolower($normalized));
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function buildMonoSummary(): array
    {
        $mono = $this->application->mono;
        if ($mono === null) {
            return [];
        }

        $lines = [];
        $map = [
            'loan_amount' => 'Financed amount',
            'down_payment' => 'Down payment',
            'total_amount' => 'Total amount',
            'repayment_duration' => 'Repayment duration (months)',
            'interest_rate' => 'Interest rate (%)',
            'credit_score' => 'Credit score',
            'status' => 'Status',
        ];

        foreach ($map as $attr => $label) {
            $val = $mono->{$attr} ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            if (in_array($attr, ['loan_amount', 'down_payment', 'total_amount'], true) && is_numeric($val)) {
                $val = $this->formatNaira((float) $val);
            }
            $lines[] = ['label' => $label, 'value' => (string) $val];
        }

        return $lines;
    }

    /**
     * @return array<string, string|null>
     */
    private function buildCreditCheckBlock(bool $isPartnerFinancing = false): array
    {
        $method = $this->application->credit_check_method;

        return [
            'method' => $method ? ucfirst(str_replace('_', ' ', (string) $method)) : ($isPartnerFinancing ? 'Partner' : null),
            'bvn' => $this->application->bvn,
            // Hide Mono status on partner-path emails.
            'mono_credit_status' => $isPartnerFinancing ? null : $this->application->mono_credit_status,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function buildBeneficiaryBlock(): array
    {
        return [
            'name' => $this->application->beneficiary_name,
            'email' => $this->application->beneficiary_email,
            'phone' => $this->application->beneficiary_phone,
            'relationship' => $this->application->beneficiary_relationship,
        ];
    }

    /**
     * @return array<int, array{label: string, attached: bool}>
     */
    private function buildKycAttachments(bool $isPartnerFinancing = false): array
    {
        $candidates = [
            [
                'label' => $isPartnerFinancing ? 'Bank statement' : 'Bank statement',
                'path' => $this->application->bank_statement_path,
            ],
            [
                'label' => $isPartnerFinancing ? 'Live selfie / passport photograph' : 'Live selfie / photo',
                'path' => $this->application->live_photo_path,
            ],
            ['label' => 'Title document (KYC)', 'path' => $this->application->title_document],
            ['label' => 'Upload document (KYC)', 'path' => $this->application->upload_document],
        ];

        $out = [];
        foreach ($candidates as $row) {
            $attached = $this->pathLooksLikeFile($row['path']);
            if ($attached) {
                $out[] = ['label' => $row['label'], 'attached' => true];
            }
        }

        $g = $this->application->guarantor;
        if ($g && $this->pathLooksLikeFile($g->signed_form_path ?? null)) {
            $out[] = ['label' => 'Guarantor signed form', 'attached' => true];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildGuarantorBlock(): ?array
    {
        $g = $this->application->guarantor;
        if ($g === null) {
            return null;
        }

        return [
            'full_name' => $g->full_name ?? null,
            'email' => $g->email ?? null,
            'phone' => $g->phone ?? null,
            'bvn' => $g->bvn ?? null,
            'relationship' => $g->relationship ?? null,
            'status' => $g->status ?? null,
            'has_signed_form' => $this->pathLooksLikeFile($g->signed_form_path ?? null),
        ];
    }

    private function pathLooksLikeFile(?string $path): bool
    {
        if ($path === null || trim($path) === '') {
            return false;
        }
        $path = trim($path);
        if (in_array(strtolower($path), ['passport', 'national id', 'drivers license', 'voters card'], true)) {
            return false;
        }

        return str_contains($path, '/') || str_contains($path, '.');
    }

    private function resolveItemableClass(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $type = str_replace('\\\\', '\\', $type);
        if (class_exists($type)) {
            return $type;
        }
        $candidate = 'App\\Models\\'.class_basename($type);
        if (class_exists($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function pickNum(mixed ...$vals): float
    {
        foreach ($vals as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $n = BnplLoanPlanCalculator::toFloat($v);

            return $n;
        }

        return 0.0;
    }

    public function formatNaira(float $amount): string
    {
        return '₦'.number_format($amount, 2, '.', ',');
    }

    private function formatSlug(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return ucwords(str_replace(['-', '_'], ' ', trim($value)));
    }

    private function formatApplicationDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
