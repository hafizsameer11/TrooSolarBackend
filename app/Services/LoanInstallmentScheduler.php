<?php

namespace App\Services;

use App\Models\LoanInstallment;
use App\Models\MonoLoanCalculation; // assumes you have this model
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanInstallmentScheduler
{
    /**
     * Sync mono financing total (upfront + principal + interest) and repair pending
     * installment rows that were generated as principal÷tenor only.
     */
    public static function syncInterestInclusiveSchedule(int $monoCalcId): void
    {
        $mono = MonoLoanCalculation::with('loanCalculation')->find($monoCalcId);
        if (! $mono) {
            return;
        }

        $principal = (float) ($mono->loan_amount ?? 0);
        $rate = (float) ($mono->interest_rate ?? 0);
        $duration = (int) ($mono->repayment_duration ?? $mono->loanCalculation?->repayment_duration ?? 0);
        $down = (float) ($mono->down_payment ?? 0);
        if ($principal > 0.005 && $rate > 0.005 && $duration > 0) {
            $totalRepay = round($principal + round($principal * ($rate / 100) * $duration, 2), 2);
            $financingTotal = round($totalRepay + $down, 2);
            if (abs((float) $mono->total_amount - $financingTotal) > 1.0) {
                $mono->total_amount = $financingTotal;
                $mono->save();
            }
            $order = \App\Models\Order::where('mono_calculation_id', $monoCalcId)
                ->where(function ($q) {
                    $q->where('order_type', 'bnpl')->orWhereNull('order_type');
                })
                ->first();
            if ($order && abs((float) $order->total_price - $financingTotal) > 1.0) {
                $order->total_price = $financingTotal;
                $order->save();
            }
        }

        self::generate($monoCalcId, null, false);
    }

    /**
     * Generate equal monthly installments for a MonoLoanCalculation.
     *
     * @param  int             $monoCalcId                 MonoLoanCalculation primary key
     * @param  Carbon|null     $firstPaymentDate           If null, uses linked LoanCalculation->repayment_date or now()->addMonth()
     * @param  bool            $forceRegenerate            If true, deletes existing PENDING installments first
     * @return array{created:int, total_amount:string}
     *
     * @throws \Throwable
     */
    public static function generate(int $monoCalcId, ?Carbon $firstPaymentDate = null, bool $forceRegenerate = false): array
    {
        $mono = MonoLoanCalculation::with('loanCalculation')->findOrFail($monoCalcId);
        $calc = $mono->loanCalculation; // must exist

        $userId     = $calc->user_id;
        // Use mono->repayment_duration as primary source (synced when counter offer accepted)
        // Fallback to calc->repayment_duration if mono doesn't have it
        $duration   = (int) ($mono->repayment_duration ?? $calc->repayment_duration);
        $perMonth   = (float) $calc->monthly_payment;

        // Safety: LoanCalculation historically stored principal÷tenor (no interest).
        // Monthly must be (principal + interest×tenor) / tenor — same as Loan Summary.
        $principal = (float) ($mono->loan_amount ?? $calc->loan_amount ?? 0);
        $interestRate = (float) ($mono->interest_rate ?? 0);
        if ($duration > 0 && $principal > 0.005 && $interestRate > 0.005) {
            $interestInclusiveMonthly = round(
                ($principal + round($principal * ($interestRate / 100) * $duration, 2)) / $duration,
                2
            );
            $principalOnlyMonthly = round($principal / $duration, 2);
            if (
                $interestInclusiveMonthly > 0.005
                && (
                    $perMonth <= 0.005
                    || abs($perMonth - $principalOnlyMonthly) < 1.0
                )
            ) {
                $perMonth = $interestInclusiveMonthly;
                $calc->monthly_payment = $perMonth;
                $calc->save();
            }
        }

        if ($duration <= 0) {
            throw new \InvalidArgumentException('repayment_duration must be > 0');
        }
        if ($perMonth <= 0) {
            throw new \InvalidArgumentException('monthly_payment must be > 0');
        }

        // Decide first due date
        if ($firstPaymentDate instanceof Carbon) {
            $due0 = $firstPaymentDate->copy();
        } elseif (!empty($calc->repayment_date)) {
            $due0 = Carbon::parse($calc->repayment_date);
        } else {
            $due0 = now()->addMonth(); // default behavior you already use
        }

        return DB::transaction(function () use ($monoCalcId, $userId, $duration, $perMonth, $due0, $forceRegenerate) {

            if ($forceRegenerate) {
                LoanInstallment::where('mono_calculation_id', $monoCalcId)
                    ->where('status', 'pending')
                    ->delete();
            } else {
                $existing = LoanInstallment::where('mono_calculation_id', $monoCalcId)->get();
                if ($existing->isNotEmpty()) {
                    // Repair principal-only schedules that were generated before interest was included.
                    $pendingOnly = $existing->every(fn ($row) => strtolower((string) $row->status) === 'pending');
                    $existingSum = round((float) $existing->sum('amount'), 2);
                    $expectedSum = round($perMonth * $duration, 2);
                    if ($pendingOnly && $expectedSum > 0 && abs($existingSum - $expectedSum) > 1.0) {
                        LoanInstallment::where('mono_calculation_id', $monoCalcId)->delete();
                    } else {
                        return [
                            'created' => 0,
                            'total_amount' => number_format($existingSum > 0 ? $existingSum : $expectedSum, 2, '.', ''),
                        ];
                    }
                }
            }

            $amounts = [];
            for ($i = 1; $i <= $duration; $i++) {
                $amounts[] = round($perMonth, 2); // base equal split
            }

            // Adjust last installment to match exact target sum
            $targetTotal = round($perMonth * $duration, 2);
            $currentSum  = round(array_sum($amounts), 2);
            $drift       = round($targetTotal - $currentSum, 2);
            $amounts[$duration - 1] = round($amounts[$duration - 1] + $drift, 2);

            $created = 0;
            foreach ($amounts as $i => $amount) {
                $dueDate = $due0->copy()->addMonths($i)->startOfDay(); // month 1..N

                LoanInstallment::create([
                    'user_id'            => $userId,
                    'status'             => 'pending',            // your existing enum/values
                    'mono_calculation_id' => $monoCalcId,         // **keep your name**
                    'amount'             => $amount,
                    'remaining_duration' => $duration - ($i + 1), // 0 at last month
                    'payment_date'       => $dueDate,
                ]);
                $created++;
            }

            return [
                'created' => $created,
                'total_amount' => number_format($targetTotal, 2, '.', ''),
            ];
        });
    }
}
