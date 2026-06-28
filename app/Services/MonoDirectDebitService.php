<?php

namespace App\Services;

use App\Models\LoanApplication;
use App\Models\LoanInstallment;
use App\Models\MonoDebitMandate;
use App\Models\MonoDebitTransaction;
use App\Models\MonoLoanCalculation;
use App\Models\User;
use App\Models\UserMonoAccount;
use App\Support\FrontendUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MonoDirectDebitService
{
    public function __construct(
        private readonly MonoService $monoService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function formatMandateSummary(?MonoDebitMandate $mandate): array
    {
        if (! $mandate) {
            return [
                'has_mandate' => false,
                'status' => null,
                'ready_to_debit' => false,
                'authorization_url' => null,
            ];
        }

        return [
            'has_mandate' => true,
            'id' => $mandate->id,
            'mono_mandate_id' => $mandate->mono_mandate_id,
            'status' => $mandate->status,
            'approved' => $mandate->approved,
            'ready_to_debit' => $mandate->ready_to_debit,
            'authorization_url' => $mandate->authorization_url,
            'amount' => round($mandate->amount_kobo / 100, 2),
            'start_date' => $mandate->start_date?->toDateString(),
            'end_date' => $mandate->end_date?->toDateString(),
            'approved_at' => $mandate->approved_at?->toIso8601String(),
            'ready_at' => $mandate->ready_at?->toIso8601String(),
        ];
    }

    public function findMandateForCalculation(int $monoCalculationId, int $userId): ?MonoDebitMandate
    {
        return MonoDebitMandate::where('mono_calculation_id', $monoCalculationId)
            ->where('user_id', $userId)
            ->whereNotIn('status', [MonoDebitMandate::STATUS_CANCELLED, MonoDebitMandate::STATUS_FAILED])
            ->latest('id')
            ->first();
    }

    /**
     * Create a variable Direct Debit mandate for BNPL repayments.
     *
     * @return array{mandate: MonoDebitMandate, authorization_url: string|null}
     */
    public function initiateMandateForLoan(User $user, MonoLoanCalculation $mono, ?int $loanApplicationId = null): array
    {
        $linked = UserMonoAccount::where('user_id', $user->id)
            ->where('status', 'linked')
            ->first();

        if (! $linked || ! $linked->mono_account_id) {
            throw new RuntimeException('Link your bank account with Mono before setting up automatic repayments.');
        }

        $existing = $this->findMandateForCalculation($mono->id, $user->id);
        if ($existing && $existing->canDebit()) {
            return [
                'mandate' => $existing,
                'authorization_url' => $existing->authorization_url,
            ];
        }

        if ($existing && $existing->authorization_url && ! $existing->ready_to_debit) {
            $this->syncMandateFromMono($existing);

            return [
                'mandate' => $existing->fresh(),
                'authorization_url' => $existing->authorization_url,
            ];
        }

        $mono->loadMissing('loanCalculation');
        $calc = $mono->loanCalculation;
        if (! $calc) {
            throw new RuntimeException('Loan calculation not found for this BNPL loan.');
        }

        $duration = max(1, (int) ($mono->repayment_duration ?? $calc->repayment_duration ?? 1));
        $monthly = (float) $calc->monthly_payment;
        if ($monthly <= 0) {
            throw new RuntimeException('Monthly repayment amount is not configured.');
        }

        $totalExposureKobo = (int) round($monthly * $duration * 100);
        $customerId = $this->ensureDirectDebitCustomer($user, $linked, $loanApplicationId);
        $address = $this->resolveCustomerAddress($user, $loanApplicationId);

        $startDate = now()->startOfDay();
        $endDate = now()->addMonths($duration)->startOfDay();
        $firstDebitDate = ! empty($calc->repayment_date)
            ? Carbon::parse($calc->repayment_date)->startOfDay()
            : now()->addMonth()->startOfDay();

        $reference = 'troosolar_mandate_' . $user->id . '_' . $mono->id . '_' . time();
        $redirectPath = $loanApplicationId
            ? '/bnpl-loans/app-' . $loanApplicationId . '?mandate=1'
            : '/bnpl-loans?mandate=1';

        $payload = [
            'amount' => $totalExposureKobo,
            'type' => 'recurring-debit',
            'method' => 'mandate',
            'mandate_type' => 'emandate',
            'debit_type' => 'variable',
            'description' => 'TrooSolar BNPL loan repayments',
            'reference' => $reference,
            'customer' => $this->buildMandateCustomerPayload($customerId, $user, $address),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'frequency' => 'monthly',
            'retrial_frequency' => 3,
            'initial_debit_date' => $firstDebitDate->format('Y-m-d'),
            'grace_period' => 3,
            'minimum_due' => 0,
            'redirect_url' => FrontendUrl::base() . $redirectPath,
            'meta' => [
                'mono_calculation_id' => $mono->id,
                'user_id' => $user->id,
            ],
        ];

        $init = $this->monoService->initiateDirectDebitMandate($payload);
        $data = is_array($init['data'] ?? null) ? $init['data'] : $init;

        $mandateId = (string) ($data['mandate_id'] ?? $data['id'] ?? '');
        $authUrl = $data['mono_url'] ?? $data['authorization_url'] ?? $data['mandate_activation_url'] ?? null;

        $mandate = MonoDebitMandate::create([
            'user_id' => $user->id,
            'mono_calculation_id' => $mono->id,
            'loan_application_id' => $loanApplicationId,
            'mono_mandate_id' => $mandateId !== '' ? $mandateId : null,
            'mono_customer_id' => $customerId,
            'mono_account_id' => $linked->mono_account_id,
            'reference' => $reference,
            'status' => MonoDebitMandate::STATUS_PENDING,
            'authorization_url' => is_string($authUrl) ? $authUrl : null,
            'amount_kobo' => $totalExposureKobo,
            'debit_type' => 'variable',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'meta' => ['init_response' => $data],
        ]);

        return [
            'mandate' => $mandate,
            'authorization_url' => $mandate->authorization_url,
        ];
    }

    public function syncMandateFromMono(MonoDebitMandate $mandate): MonoDebitMandate
    {
        if (! $mandate->mono_mandate_id) {
            return $mandate;
        }

        try {
            $response = $this->monoService->retrieveMandate($mandate->mono_mandate_id);
            $data = is_array($response['data'] ?? null) ? $response['data'] : $response;

            $status = strtolower((string) ($data['status'] ?? ''));
            $approved = (bool) ($data['approved'] ?? false);
            $ready = (bool) ($data['ready_to_debit'] ?? false);

            $updates = [
                'approved' => $approved,
                'ready_to_debit' => $ready,
                'meta' => array_merge($mandate->meta ?? [], ['last_sync' => $data]),
            ];

            if ($approved && ! $mandate->approved_at) {
                $updates['approved_at'] = now();
                $updates['status'] = MonoDebitMandate::STATUS_APPROVED;
            }

            if ($ready) {
                $updates['ready_at'] = $mandate->ready_at ?? now();
                $updates['status'] = MonoDebitMandate::STATUS_READY;
            } elseif ($approved) {
                $updates['status'] = MonoDebitMandate::STATUS_APPROVED;
            } elseif (in_array($status, ['cancelled', 'rejected', 'failed'], true)) {
                $updates['status'] = $status === 'cancelled'
                    ? MonoDebitMandate::STATUS_CANCELLED
                    : MonoDebitMandate::STATUS_FAILED;
            }

            $mandate->update($updates);
        } catch (\Throwable $e) {
            Log::warning('Mono mandate sync failed: ' . $e->getMessage(), [
                'mandate_id' => $mandate->id,
            ]);
        }

        return $mandate->fresh();
    }

    /**
     * Debit an installment from an approved mandate and mark installment paid on success.
     */
    public function collectInstallment(LoanInstallment $installment): MonoDebitTransaction
    {
        if ($installment->status === LoanInstallment::STATUS_PAID) {
            throw new RuntimeException('Installment is already paid.');
        }

        $mandate = $this->findMandateForCalculation((int) $installment->mono_calculation_id, (int) $installment->user_id);
        if (! $mandate) {
            throw new RuntimeException('No Mono Direct Debit mandate found for this loan.');
        }

        $mandate = $this->syncMandateFromMono($mandate);
        if (! $mandate->canDebit()) {
            throw new RuntimeException(
                'Your Mono repayment mandate is not ready yet. Complete authorization in Mono and wait for bank approval (can take up to 72 hours).'
            );
        }

        $amountKobo = (int) round(((float) $installment->amount) * 100);
        $reference = 'troosolar_inst_' . $installment->id . '_' . time();

        $debitTx = MonoDebitTransaction::create([
            'mono_debit_mandate_id' => $mandate->id,
            'loan_installment_id' => $installment->id,
            'reference' => $reference,
            'amount_kobo' => $amountKobo,
            'status' => MonoDebitTransaction::STATUS_PENDING,
        ]);

        try {
            $balance = $this->monoService->mandateBalanceInquiry($mandate->mono_mandate_id);
            $balanceData = is_array($balance['data'] ?? null) ? $balance['data'] : $balance;
            $hasFunds = (bool) ($balanceData['has_sufficient_balance'] ?? $balanceData['has_sufficient_funds'] ?? false);

            if (! $hasFunds) {
                throw new RuntimeException('Insufficient balance in the linked bank account for this debit.');
            }

            $debitResponse = $this->monoService->debitMandateAccount($mandate->mono_mandate_id, [
                'amount' => $amountKobo,
                'reference' => $reference,
                'narration' => 'TrooSolar BNPL installment #' . $installment->id,
            ]);

            $debitData = is_array($debitResponse['data'] ?? null) ? $debitResponse['data'] : $debitResponse;
            $debitStatus = strtolower((string) ($debitData['status'] ?? $debitResponse['status'] ?? ''));

            if (! in_array($debitStatus, ['successful', 'success', 'completed'], true)) {
                throw new RuntimeException('Mono debit was not successful.');
            }

            DB::transaction(function () use ($installment, $debitTx, $debitResponse, $reference, $amountKobo) {
                $debitTx->update([
                    'status' => MonoDebitTransaction::STATUS_SUCCESSFUL,
                    'mono_response' => $debitResponse,
                ]);

                app(InstallmentPaymentRecorder::class)->markPaidFromMonoDebit(
                    $installment,
                    $reference,
                    $amountKobo / 100
                );
            });

            return $debitTx->fresh();
        } catch (\Throwable $e) {
            $debitTx->update([
                'status' => MonoDebitTransaction::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handleMandateWebhook(string $event, array $data): void
    {
        $mandateId = (string) ($data['id'] ?? $data['mandate'] ?? $data['mandate_id'] ?? '');
        $reference = (string) ($data['reference'] ?? '');

        $mandate = null;
        if ($mandateId !== '') {
            $mandate = MonoDebitMandate::where('mono_mandate_id', $mandateId)->first();
        }
        if (! $mandate && $reference !== '') {
            $mandate = MonoDebitMandate::where('reference', $reference)->first();
        }

        if (! $mandate) {
            Log::info('Mono mandate webhook: no local mandate', ['event' => $event, 'mandate_id' => $mandateId]);

            return;
        }

        if (str_contains($event, 'approved')) {
            $mandate->update([
                'approved' => true,
                'approved_at' => $mandate->approved_at ?? now(),
                'status' => MonoDebitMandate::STATUS_APPROVED,
            ]);
        }

        if (str_contains($event, 'ready')) {
            $mandate->update([
                'approved' => true,
                'ready_to_debit' => true,
                'ready_at' => $mandate->ready_at ?? now(),
                'status' => MonoDebitMandate::STATUS_READY,
            ]);
        }

        if (str_contains($event, 'cancelled') || str_contains($event, 'rejected')) {
            $mandate->update([
                'status' => str_contains($event, 'cancelled')
                    ? MonoDebitMandate::STATUS_CANCELLED
                    : MonoDebitMandate::STATUS_FAILED,
                'ready_to_debit' => false,
            ]);
        }

        $this->syncMandateFromMono($mandate);
    }

    private function ensureDirectDebitCustomer(User $user, UserMonoAccount $linked, ?int $loanApplicationId = null): string
    {
        $ddCustomerId = $linked->mono_dd_customer_id;
        $connectCustomerId = $linked->mono_customer_id;

        // Reuse only a dedicated Direct Debit customer — not the Connect-only customer id.
        if ($ddCustomerId && ($connectCustomerId === null || $ddCustomerId !== $connectCustomerId)) {
            return $ddCustomerId;
        }

        $address = $this->resolveCustomerAddress($user, $loanApplicationId);
        $bvn = preg_replace('/\s+/', '', trim((string) ($user->bvn ?? '')));

        $payload = [
            'email' => (string) ($user->email ?? ''),
            'firstName' => (string) ($user->first_name ?? 'Customer'),
            'lastName' => (string) ($user->sur_name ?? 'User'),
            'address' => $address,
            'type' => 'individual',
        ];

        if ($user->phone) {
            $payload['phone'] = (string) $user->phone;
        }

        if ($bvn !== '') {
            $payload['identity'] = ['type' => 'bvn', 'number' => $bvn];
        }

        $created = $this->monoService->createCustomer($payload);
        $data = is_array($created['data'] ?? null) ? $created['data'] : $created;
        $customerId = (string) ($data['id'] ?? $data['_id'] ?? '');

        if ($customerId === '') {
            throw new RuntimeException('Mono could not create a Direct Debit customer profile.');
        }

        $linked->update(['mono_dd_customer_id' => $customerId]);

        return $customerId;
    }

    /**
     * @return array<string, string>
     */
    private function buildMandateCustomerPayload(string $customerId, User $user, string $address): array
    {
        $payload = [
            'id' => $customerId,
            'address' => $address,
        ];

        if ($user->phone) {
            $payload['phone'] = (string) $user->phone;
        }

        return $payload;
    }

    private function resolveCustomerAddress(User $user, ?int $loanApplicationId = null): string
    {
        if ($loanApplicationId) {
            $application = LoanApplication::query()
                ->where('id', $loanApplicationId)
                ->where('user_id', $user->id)
                ->first(['property_address', 'property_state', 'estate_address', 'is_gated_estate']);

            if ($application) {
                $lines = array_filter([
                    trim((string) ($application->property_address ?? '')),
                    $application->is_gated_estate ? trim((string) ($application->estate_address ?? '')) : null,
                ]);

                foreach ($lines as $line) {
                    if ($line !== '') {
                        $state = trim((string) ($application->property_state ?? ''));

                        return implode(', ', array_filter([$line, $state, 'Nigeria']));
                    }
                }

                $state = trim((string) ($application->property_state ?? ''));
                if ($state !== '') {
                    return $state . ', Nigeria';
                }
            }
        }

        return '34 Adeola Odeku Street, Victoria Island, Lagos, Nigeria';
    }
}
