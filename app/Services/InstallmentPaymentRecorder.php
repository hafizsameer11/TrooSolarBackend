<?php

namespace App\Services;

use App\Mail\BNPLStatusEmail;
use App\Models\LoanApplication;
use App\Models\LoanInstallment;
use App\Models\LoanRepayment;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Transaction;
use App\Support\MailBrand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Shared logic to mark installments paid (wallet, Flutterwave, Mono Direct Debit).
 */
class InstallmentPaymentRecorder
{
    public const ORDER_STATUS_PAYMENT_COMPLETE = 'payment_complete';

    public const APPLICATION_STATUS_PAYMENT_COMPLETE = 'payment_complete';

    public function markPaidFromMonoDebit(LoanInstallment $installment, string $reference, float $amount): LoanInstallment
    {
        $inst = LoanInstallment::where('id', $installment->id)->lockForUpdate()->firstOrFail();

        if ($inst->status === LoanInstallment::STATUS_PAID) {
            return $inst;
        }

        $transaction = Transaction::create([
            'title' => 'BNPL installment (Mono Direct Debit)',
            'amount' => $amount,
            'status' => 'success',
            'type' => 'debit',
            'method' => 'mono_direct_debit',
            'transacted_at' => now(),
            'user_id' => $inst->user_id,
            'tx_id' => $reference,
            'reference' => 'INSTALLMENT#' . $inst->id,
        ]);

        $inst->update([
            'status' => LoanInstallment::STATUS_PAID,
            'paid_at' => now(),
            'transaction_id' => $transaction->id,
        ]);

        LoanRepayment::create([
            'amount' => $amount,
            'user_id' => $inst->user_id,
            'mono_calculation_id' => $inst->mono_calculation_id,
        ]);

        $this->syncOrderPaymentCompleteForMono($inst->mono_calculation_id);

        return $inst->fresh();
    }

    /**
     * When every installment for a mono calculation is paid:
     * - mark linked BNPL order(s) as payment_complete
     * - mark linked loan application(s) as payment_complete
     * - send completion email + in-app notification once per application transition
     */
    public function syncOrderPaymentCompleteForMono(?int $monoCalculationId): bool
    {
        if (!$monoCalculationId) {
            return false;
        }

        $hasInstallments = LoanInstallment::where('mono_calculation_id', $monoCalculationId)->exists();
        if (!$hasInstallments) {
            return false;
        }

        $hasUnpaid = LoanInstallment::where('mono_calculation_id', $monoCalculationId)
            ->where('status', '!=', LoanInstallment::STATUS_PAID)
            ->exists();

        if ($hasUnpaid) {
            return false;
        }

        Order::where('mono_calculation_id', $monoCalculationId)
            ->where(function ($q) {
                $q->whereNull('order_status')
                    ->orWhereNotIn('order_status', [
                        self::ORDER_STATUS_PAYMENT_COMPLETE,
                        'completed',
                    ]);
            })
            ->update(['order_status' => self::ORDER_STATUS_PAYMENT_COMPLETE]);

        $applications = LoanApplication::with('user')
            ->where('mono_loan_calculation', $monoCalculationId)
            ->get();

        foreach ($applications as $application) {
            $previousStatus = strtolower((string) ($application->status ?? ''));
            if ($previousStatus === self::APPLICATION_STATUS_PAYMENT_COMPLETE) {
                continue;
            }

            // Do not override terminal reject/cancel; all other statuses become payment_complete
            // once every installment for this loan plan is paid.
            if (in_array($previousStatus, ['rejected', 'cancelled'], true)) {
                continue;
            }

            $application->status = self::APPLICATION_STATUS_PAYMENT_COMPLETE;
            $application->save();

            $this->notifyPaymentComplete($application->fresh(['user', 'mono']));
        }

        return true;
    }

    private function notifyPaymentComplete(LoanApplication $application): void
    {
        $user = $application->user;
        if (! $user) {
            return;
        }

        $bnpl = MailBrand::BNPL_LABEL;
        $message = "Congratulations! You have completed all payments on your {$bnpl} loan (Application #{$application->id}).";

        try {
            Notification::create([
                'user_id' => $user->id,
                'message' => $message,
                'type' => 'bnpl_status',
            ]);
        } catch (\Throwable $e) {
            Log::warning('BNPL payment complete notification failed: '.$e->getMessage(), [
                'application_id' => $application->id,
                'user_id' => $user->id,
            ]);
        }

        if (empty($user->email)) {
            return;
        }

        try {
            Mail::to($user->email)->send(
                new BNPLStatusEmail($user, $application, self::APPLICATION_STATUS_PAYMENT_COMPLETE)
            );
        } catch (\Throwable $e) {
            Log::warning('BNPL payment complete email failed: '.$e->getMessage(), [
                'application_id' => $application->id,
                'user_id' => $user->id,
            ]);
        }
    }
}
