<?php

namespace App\Services;

/**
 * Mirrors customer BNPL "Review Your Loan Plan" math (LoanCalculator.jsx + BNPLFlow):
 * - Deposit % / loan principal on loan calculator total (invoice grand total)
 * - Insurance % of catalog bundle price (order Sub-Total), NOT of calculator total
 * - Management / legal % of loan amount
 */
class BnplLoanPlanCalculator
{
    public static function toFloat(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $s = preg_replace('/,/', '', (string) $v);

        return is_numeric($s) ? (float) $s : 0.0;
    }

    /**
     * Loan calculator total (principal + equity deposit) — same as LoanCalculator grandTotal.
     *
     * @param  array<string, mixed>|null  $snap  loan_plan_snapshot (formData.loanDetails JSON)
     */
    public static function loanCalculatorTotalFromSnapshot(?array $snap): float
    {
        if (! is_array($snap) || $snap === []) {
            return 0.0;
        }
        $principal = self::toFloat($snap['principal'] ?? $snap['totalLoanAmount'] ?? 0);
        $baseDep = self::toFloat($snap['baseDepositAmount'] ?? 0);
        if ($principal > 0 && $baseDep >= 0) {
            return max($principal + $baseDep, 0.0);
        }
        $grand = self::toFloat($snap['grandTotal'] ?? $snap['invoiceGrandTotal'] ?? 0);
        if ($grand > 0) {
            return $grand;
        }
        // After Review proceed, totalAmount may be grandTotal + admin fees.
        $totalAmount = self::toFloat($snap['totalAmount'] ?? 0);
        $adminFees = self::toFloat($snap['adminFeesTotal'] ?? $snap['feesTotal'] ?? 0);
        if ($adminFees <= 0) {
            $adminFees = self::toFloat($snap['insuranceFee'] ?? 0)
                + self::toFloat($snap['managementFee'] ?? 0)
                + self::toFloat($snap['legalFee'] ?? 0);
        }
        if ($totalAmount > 0 && $adminFees > 0) {
            return max($totalAmount - $adminFees, 0.0);
        }
        if ($totalAmount > 0) {
            return $totalAmount;
        }

        return 0.0;
    }

    /**
     * Catalog bundle / product subtotal for insurance — same as LoanCalculator bundlePrice prop.
     * Never returns the VAT-inclusive loan calculator total.
     *
     * @param  array<string, mixed>|null  $snap
     */
    public static function catalogBundlePriceFromSnapshot(?array $snap): float
    {
        if (! is_array($snap) || $snap === []) {
            return 0.0;
        }
        foreach (['bundlePrice', 'catalogSubtotal', 'netAmount', 'amountExcludingVat'] as $k) {
            $v = self::toFloat($snap[$k] ?? 0);
            if ($v > 0) {
                return $v;
            }
        }

        // Older snapshots: reverse VAT from the VAT-inclusive calculator total.
        $loanCalc = self::loanCalculatorTotalFromSnapshot($snap);
        $vatAmt = self::toFloat($snap['vatAmount'] ?? 0);
        if ($loanCalc > 0 && $vatAmt > 0 && $vatAmt < $loanCalc) {
            return round($loanCalc - $vatAmt, 2);
        }
        $vatPct = self::toFloat($snap['vatPercent'] ?? 7.5);
        if ($vatPct <= 0) {
            $vatPct = 7.5;
        }
        if ($loanCalc > 0) {
            return round($loanCalc / (1 + ($vatPct / 100.0)), 2);
        }

        return 0.0;
    }

    /**
     * @deprecated Use loanCalculatorTotalFromSnapshot — kept for call-site compatibility.
     *
     * @param  array<string, mixed>|null  $snap
     */
    public static function bundlePriceFromSnapshot(?array $snap): float
    {
        return self::loanCalculatorTotalFromSnapshot($snap);
    }

    /**
     * @param  array<string, mixed>|null  $snap
     * @return array{insurance: float, management: float, legal: float}
     */
    public static function feePercentagesFromSnapshot(?array $snap): array
    {
        $fp = is_array($snap) && isset($snap['feePercentages']) && is_array($snap['feePercentages'])
            ? $snap['feePercentages']
            : null;
        if ($fp !== null) {
            return [
                'insurance' => self::toFloat($fp['insurance'] ?? 3),
                'management' => self::toFloat($fp['management'] ?? 1),
                'legal' => self::toFloat($fp['legal'] ?? 1),
            ];
        }

        return [
            'insurance' => self::toFloat($snap['insurancePct'] ?? $snap['insurance_fee_percentage'] ?? 3),
            'management' => self::toFloat($snap['managementPct'] ?? $snap['management_fee_percentage'] ?? 1),
            'legal' => self::toFloat($snap['legalPct'] ?? $snap['legal_fee_percentage'] ?? 1),
        ];
    }

    public static function interestMonthlyPercentFromSnapshot(?array $snap, float $fallback = 4.0): float
    {
        if (! is_array($snap)) {
            return $fallback;
        }
        foreach (['interestRate', 'interest_rate'] as $k) {
            if (isset($snap[$k]) && $snap[$k] !== '' && is_numeric($snap[$k])) {
                return (float) $snap[$k];
            }
        }

        return $fallback;
    }

    /**
     * @param  array{insurance: float, management: float, legal: float}  $feePcts
     * @return array<string, float>
     */
    public static function computeFromDepositPercent(
        float $loanCalculatorTotal,
        float $depositPercentOfBundle,
        int $tenorMonths,
        float $interestMonthlyPercent,
        array $feePcts,
        ?float $catalogBundlePrice = null
    ): array {
        $i = $feePcts['insurance'] / 100;
        $m = $feePcts['management'] / 100;
        $l = $feePcts['legal'] / 100;

        // Never use VAT-inclusive loan calculator total as insurance base.
        $insuranceBase = ($catalogBundlePrice !== null && $catalogBundlePrice > 0)
            ? $catalogBundlePrice
            : 0.0;
        if ($insuranceBase <= 0 && $loanCalculatorTotal > 0) {
            $insuranceBase = round($loanCalculatorTotal / 1.075, 2);
        }

        // Same as LoanCalculator.jsx: deposit on grandTotal; insurance on catalog bundle price
        $baseDeposit = $loanCalculatorTotal * ($depositPercentOfBundle / 100.0);
        $baseLoanAmount = max($loanCalculatorTotal - $baseDeposit, 0.0);

        $insuranceFee = round($insuranceBase * $i, 2);
        $managementFee = round($baseLoanAmount * $m, 2);
        $legalFee = round($baseLoanAmount * $l, 2);
        $feesTotal = round($insuranceFee + $managementFee + $legalFee, 2);
        $upfrontDepositTotal = round($baseDeposit + $feesTotal, 2);

        $totalInterestAmount = round($baseLoanAmount * ($interestMonthlyPercent / 100) * max($tenorMonths, 0), 2);
        $totalRepaymentAmount = round($baseLoanAmount + $totalInterestAmount, 2);
        $monthlyRepaymentAmount = $tenorMonths > 0 ? round($totalRepaymentAmount / $tenorMonths, 2) : 0.0;

        return [
            'bundle_price' => round($loanCalculatorTotal, 2),
            'catalog_bundle_price' => round($insuranceBase, 2),
            'deposit_percent' => $depositPercentOfBundle,
            'base_deposit' => $baseDeposit,
            'base_loan_amount' => $baseLoanAmount,
            'insurance_fee' => $insuranceFee,
            'management_fee' => $managementFee,
            'legal_fee' => $legalFee,
            'admin_fees_total' => $feesTotal,
            'upfront_deposit_total' => $upfrontDepositTotal,
            'total_loan_amount' => $baseLoanAmount,
            'total_interest_amount' => $totalInterestAmount,
            'total_repayment_amount' => $totalRepaymentAmount,
            'monthly_repayment_amount' => $monthlyRepaymentAmount,
        ];
    }

    /**
     * Recover equity deposit (before fees) from total upfront paid (deposit + admin fees).
     *
     * @param  array{insurance: float, management: float, legal: float}  $feePcts
     */
    public static function solveBaseDepositFromUpfront(
        float $loanCalculatorTotal,
        float $upfrontTotal,
        array $feePcts,
        ?float $catalogBundlePrice = null
    ): float {
        if ($loanCalculatorTotal <= 0 || $upfrontTotal <= 0) {
            return 0.0;
        }
        $i = $feePcts['insurance'] / 100;
        $m = $feePcts['management'] / 100;
        $l = $feePcts['legal'] / 100;
        $denom = 1 - $m - $l;
        if ($denom <= 0.0001) {
            return 0.0;
        }
        $insuranceBase = ($catalogBundlePrice !== null && $catalogBundlePrice > 0)
            ? $catalogBundlePrice
            : 0.0;
        if ($insuranceBase <= 0 && $loanCalculatorTotal > 0) {
            $insuranceBase = round($loanCalculatorTotal / 1.075, 2);
        }
        // upfront = baseDeposit + insuranceBase*i + (loanCalcTotal - baseDeposit)*(m+l)
        $base = ($upfrontTotal - $insuranceBase * $i - $loanCalculatorTotal * ($m + $l)) / $denom;

        return max(0.0, round($base, 2));
    }

    /**
     * Build full plan from stored counter-offer upfront total (matches mono.down_payment semantics on submit).
     *
     * @param  array{insurance: float, management: float, legal: float}  $feePcts
     * @return array<string, float>
     */
    public static function computeFromUpfrontTotal(
        float $loanCalculatorTotal,
        float $upfrontTotal,
        int $tenorMonths,
        float $interestMonthlyPercent,
        array $feePcts,
        ?float $catalogBundlePrice = null
    ): array {
        $baseDeposit = self::solveBaseDepositFromUpfront(
            $loanCalculatorTotal,
            $upfrontTotal,
            $feePcts,
            $catalogBundlePrice
        );
        $depositPercent = $loanCalculatorTotal > 0 ? ($baseDeposit / $loanCalculatorTotal) * 100 : 0.0;

        return self::computeFromDepositPercent(
            $loanCalculatorTotal,
            $depositPercent,
            $tenorMonths,
            $interestMonthlyPercent,
            $feePcts,
            $catalogBundlePrice
        );
    }

    /**
     * Deposit % of loan calculator total implied by a stored upfront (equity + admin fees).
     *
     * @param  array{insurance: float, management: float, legal: float}  $feePcts
     */
    public static function depositPercentFromUpfrontTotal(
        float $loanCalculatorTotal,
        float $upfrontTotal,
        array $feePcts,
        ?float $catalogBundlePrice = null
    ): float {
        $base = self::solveBaseDepositFromUpfront(
            $loanCalculatorTotal,
            $upfrontTotal,
            $feePcts,
            $catalogBundlePrice
        );
        if ($loanCalculatorTotal <= 0 || $base <= 0) {
            return 0.0;
        }

        return min(100.0, ($base / $loanCalculatorTotal) * 100.0);
    }

    /**
     * Resolve counter-offer plan from stored ₦ upfront + tenor.
     * Admin enters whole deposit %; older rows may have inflated insurance (1% of calculator total).
     * Prefer the invert that lands closest to a whole percent, then recompute with catalog-bundle insurance.
     *
     * @param  array<string, mixed>|null  $snap
     * @return array<string, float>|null
     */
    public static function resolveCounterOfferPlanFromStored(
        ?array $snap,
        float $storedUpfront,
        int $tenorMonths,
        float $interestMonthlyFallback = 4.0
    ): ?array {
        $loanCalcTotal = self::loanCalculatorTotalFromSnapshot($snap);
        $catalogBundle = self::catalogBundlePriceFromSnapshot($snap);
        $feePcts = self::feePercentagesFromSnapshot($snap);
        $interestMonthly = self::interestMonthlyPercentFromSnapshot($snap, $interestMonthlyFallback);
        if ($loanCalcTotal <= 0 || $storedUpfront <= 0 || $tenorMonths <= 0) {
            return null;
        }

        // Always invert / recompute with catalog-bundle insurance (never loan-calculator total).
        $catalogOrNull = $catalogBundle > 0 ? $catalogBundle : null;
        $pctCatalog = self::depositPercentFromUpfrontTotal(
            $loanCalcTotal,
            $storedUpfront,
            $feePcts,
            $catalogOrNull
        );
        if ($pctCatalog <= 0) {
            return null;
        }
        // Admin always enters whole deposit % (e.g. 30, 40, 50).
        $depositPercent = round($pctCatalog);

        return self::computeFromDepositPercent(
            $loanCalcTotal,
            $depositPercent,
            $tenorMonths,
            $interestMonthly,
            $feePcts,
            $catalogOrNull
        );
    }

    /**
     * API-ready counter-offer breakdown (persists for any status that still has stored terms).
     *
     * @param  array<string, mixed>|null  $snap
     * @return array<string, mixed>|null
     */
    public static function buildCounterOfferDetailsPayload(
        ?array $snap,
        float $storedUpfront,
        int $tenorMonths,
        float $interestMonthlyFallback = 4.0
    ): ?array {
        $plan = self::resolveCounterOfferPlanFromStored(
            $snap,
            $storedUpfront,
            $tenorMonths,
            $interestMonthlyFallback
        );
        if ($plan === null) {
            return null;
        }
        $feePcts = self::feePercentagesFromSnapshot($snap);
        $interestMonthly = self::interestMonthlyPercentFromSnapshot($snap, $interestMonthlyFallback);
        $depositPct = (float) ($plan['deposit_percent'] ?? 0);
        $correctedUpfront = (float) $plan['upfront_deposit_total'];

        return [
            'bundle_price' => number_format($plan['bundle_price'], 2),
            'catalog_bundle_price' => number_format($plan['catalog_bundle_price'] ?? 0, 2),
            'deposit_percent' => $depositPct,
            'loan_amount' => number_format($plan['total_loan_amount'], 2),
            'total_loan_amount' => number_format($plan['total_loan_amount'], 2),
            'down_payment' => number_format($correctedUpfront, 2),
            'upfront_deposit_total' => number_format($correctedUpfront, 2),
            'base_deposit' => number_format($plan['base_deposit'], 2),
            'admin_fees_total' => number_format($plan['admin_fees_total'], 2),
            'insurance_fee' => number_format($plan['insurance_fee'], 2),
            'management_fee' => number_format($plan['management_fee'], 2),
            'legal_fee' => number_format($plan['legal_fee'], 2),
            'insurance_pct' => $feePcts['insurance'],
            'management_pct' => $feePcts['management'],
            'legal_pct' => $feePcts['legal'],
            'total_interest_amount' => number_format($plan['total_interest_amount'], 2),
            'repayment_duration' => $tenorMonths,
            'interest_rate' => $interestMonthly,
            'total_amount' => number_format($plan['total_repayment_amount'], 2),
            'total_repayment_amount' => number_format($plan['total_repayment_amount'], 2),
            'monthly_payment' => number_format($plan['monthly_repayment_amount'], 2),
            'monthly_repayment_amount' => number_format($plan['monthly_repayment_amount'], 2),
        ];
    }
}
