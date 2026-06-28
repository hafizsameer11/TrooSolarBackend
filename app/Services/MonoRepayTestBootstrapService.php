<?php

namespace App\Services;

use App\Models\BnplSettings;
use App\Models\Bundles;
use App\Models\LoanApplication;
use App\Models\LoanCalculation;
use App\Models\LoanInstallment;
use App\Models\MonoLoanCalculation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\UserMonoAccount;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class MonoRepayTestBootstrapService
{
    public const SNAPSHOT_FLAG = 'mono_repay_test';

    public function __construct(
        private readonly MonoDirectDebitService $directDebitService
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getStatus(User $user): ?array
    {
        $existing = $this->findExistingTestApplication($user->id);
        if (! $existing) {
            return null;
        }

        return $this->formatBootstrapResult($user, $existing);
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(User $user, bool $forceRegenerate = false): array
    {
        $linked = UserMonoAccount::where('user_id', $user->id)
            ->where('status', 'linked')
            ->first();

        if (! $linked || ! $linked->mono_account_id) {
            throw new RuntimeException('Link your bank with Mono before starting the repayment test.');
        }

        $existing = $this->findExistingTestApplication($user->id);
        if ($existing && ! $forceRegenerate) {
            return $this->formatBootstrapResult($user, $existing);
        }

        return DB::transaction(function () use ($user, $forceRegenerate, $existing) {
            if ($forceRegenerate && $existing) {
                $this->archiveExistingTestRecords($existing);
            }

            $bundle = $this->resolveTestBundle();
            $bundlePrice = $this->resolveBundleUnitPrice($bundle);
            $installmentAmount = (float) config('bnpl_mono_repay_test.installment_amount', 2000);
            $installmentCount = max(1, (int) config('bnpl_mono_repay_test.installment_count', 3));
            $downPayment = max(0.0, (float) config('bnpl_mono_repay_test.down_payment', 1000));
            $principal = round($installmentAmount * $installmentCount, 2);
            $totalAmount = round($downPayment + $principal, 2);
            $dueToday = (bool) config('bnpl_mono_repay_test.due_today', true);
            $firstDue = $dueToday ? Carbon::today() : Carbon::today()->addMonth();

            $settings = BnplSettings::get();

            $loanCalculation = LoanCalculation::create([
                'user_id' => $user->id,
                'loan_amount' => $principal,
                'repayment_duration' => $installmentCount,
                'status' => 'finalized',
                'product_amount' => $bundlePrice,
                'monthly_payment' => $installmentAmount,
                'repayment_date' => $firstDue->toDateString(),
                'interest_percentage' => (float) ($settings->interest_rate_percentage ?? 0),
            ]);

            $mono = MonoLoanCalculation::create([
                'loan_calculation_id' => $loanCalculation->id,
                'loan_amount' => $principal,
                'repayment_duration' => $installmentCount,
                'down_payment' => $downPayment,
                'total_amount' => $totalAmount,
                'status' => 'approved',
                'interest_rate' => (float) ($settings->interest_rate_percentage ?? 0),
                'management_fee_percentage' => (float) ($settings->management_fee_percentage ?? 0),
                'legal_fee_percentage' => (float) ($settings->legal_fee_percentage ?? 0),
                'insurance_fee_percentage' => (float) ($settings->insurance_fee_percentage ?? 0),
            ]);

            $orderItemsSnapshot = [[
                'itemable_type' => Bundles::class,
                'itemable_id' => $bundle->id,
                'quantity' => 1,
                'unit_price' => $bundlePrice,
                'subtotal' => $bundlePrice,
            ]];

            $application = LoanApplication::create([
                'user_id' => $user->id,
                'mono_loan_calculation' => $mono->id,
                'status' => 'approved',
                'loan_amount' => $principal,
                'repayment_duration' => $installmentCount,
                'customer_type' => 'residential',
                'product_category' => 'full-kit',
                'credit_check_method' => 'auto',
                'property_state' => 'Lagos',
                'property_address' => 'Mono repay test — no delivery',
                'admin_notes' => 'MONO_REPAY_TEST — isolated mandate/debit test loan. Not a real disbursement.',
                'order_items_snapshot' => $orderItemsSnapshot,
                'loan_plan_snapshot' => [
                    self::SNAPSHOT_FLAG => true,
                    'installment_amount' => $installmentAmount,
                    'installment_count' => $installmentCount,
                    'down_payment' => $downPayment,
                    'bundle_id' => $bundle->id,
                    'bundle_title' => $bundle->title,
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            $mono->loan_application_id = $application->id;
            $mono->save();

            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => strtoupper('TEST-' . Str::random(8)),
                'total_price' => $totalAmount,
                'payment_status' => 'paid',
                'order_status' => 'pending',
                'payment_method' => 'mono_repay_test',
                'mono_calculation_id' => $mono->id,
                'order_type' => 'bnpl',
                'bundle_id' => $bundle->id,
                'note' => 'MONO_REPAY_TEST',
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'itemable_type' => Bundles::class,
                'itemable_id' => $bundle->id,
                'quantity' => 1,
                'unit_price' => $bundlePrice,
                'subtotal' => $bundlePrice,
            ]);

            LoanInstallmentScheduler::generate($mono->id, $firstDue->copy(), true);

            if ($principal > 0) {
                $wallet = Wallet::firstOrCreate(
                    ['user_id' => $user->id],
                    ['loan_balance' => 0, 'shop_balance' => 0]
                );
                $wallet->loan_balance = (float) $wallet->loan_balance + $principal;
                $wallet->save();
            }

            return $this->formatBootstrapResult($user, $application->fresh(['mono.loanCalculation']));
        });
    }

    /**
     * Set all pending installments on the user's active test loan to due today.
     *
     * @return array<string, mixed>
     */
    public function refreshDueDates(User $user): array
    {
        $application = $this->findExistingTestApplication($user->id);
        if (! $application || ! $application->mono) {
            throw new RuntimeException('No Mono repayment test loan found. Run bootstrap first.');
        }

        $monoId = (int) $application->mono->id;
        $updated = LoanInstallment::where('mono_calculation_id', $monoId)
            ->where('user_id', $user->id)
            ->where('status', LoanInstallment::STATUS_PENDING)
            ->update(['payment_date' => Carbon::today()->toDateString()]);

        $calc = $application->mono->loanCalculation;
        if ($calc) {
            $calc->repayment_date = Carbon::today()->toDateString();
            $calc->save();
        }

        return [
            'updated_installments' => $updated,
            'bootstrap' => $this->formatBootstrapResult($user, $application->fresh(['mono.loanCalculation'])),
        ];
    }

    private function findExistingTestApplication(int $userId): ?LoanApplication
    {
        return LoanApplication::with(['mono.loanCalculation', 'mono.loanInstallments'])
            ->where('user_id', $userId)
            ->where('loan_plan_snapshot->'.self::SNAPSHOT_FLAG, true)
            ->whereIn('status', ['approved', 'counter_offer_accepted'])
            ->latest('id')
            ->first();
    }

    private function archiveExistingTestRecords(LoanApplication $application): void
    {
        $snapshot = is_array($application->loan_plan_snapshot) ? $application->loan_plan_snapshot : [];
        $snapshot[self::SNAPSHOT_FLAG] = false;
        $snapshot['archived_at'] = now()->toIso8601String();
        $application->loan_plan_snapshot = $snapshot;
        $application->status = 'cancelled';
        $application->admin_notes = trim(($application->admin_notes ?? '').' [archived for new mono repay test]');
        $application->save();
    }

    private function resolveTestBundle(): Bundles
    {
        $bundleId = (int) config('bnpl_mono_repay_test.bundle_id', 0);
        if ($bundleId > 0) {
            $bundle = Bundles::query()->find($bundleId);
            if ($bundle) {
                return $bundle;
            }
        }

        $query = Bundles::query();
        if (Schema::hasColumn('bundles', 'is_available')) {
            $query->where('is_available', true);
        }

        $bundle = $query->orderByRaw('COALESCE(discount_price, total_price) asc')->first();
        if (! $bundle) {
            throw new RuntimeException('No bundle found for Mono repayment test. Set BNPL_MONO_REPAY_TEST_BUNDLE_ID.');
        }

        return $bundle;
    }

    private function resolveBundleUnitPrice(Bundles $bundle): float
    {
        $discount = (float) ($bundle->discount_price ?? 0);
        $total = (float) ($bundle->total_price ?? 0);

        return $discount > 0 ? $discount : ($total > 0 ? $total : 10000.0);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBootstrapResult(User $user, LoanApplication $application): array
    {
        $application->loadMissing(['mono.loanCalculation', 'mono.loanInstallments']);
        $mono = $application->mono;
        if (! $mono) {
            throw new RuntimeException('Test loan calculation missing.');
        }

        $order = Order::where('mono_calculation_id', $mono->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $mandate = $this->directDebitService->findMandateForCalculation((int) $mono->id, (int) $user->id);
        if ($mandate) {
            $mandate = $this->directDebitService->syncMandateFromMono($mandate);
        }

        $installments = $mono->loanInstallments()
            ->orderBy('payment_date')
            ->get()
            ->map(fn (LoanInstallment $row) => [
                'id' => $row->id,
                'amount' => (float) $row->amount,
                'payment_date' => $row->payment_date?->format('Y-m-d'),
                'status' => $row->status,
            ])
            ->values()
            ->all();

        return [
            'application_id' => $application->id,
            'order_id' => $order?->id,
            'order_number' => $order?->order_number,
            'mono_calculation_id' => (int) $mono->id,
            'loan_amount' => (float) $mono->loan_amount,
            'down_payment' => (float) $mono->down_payment,
            'total_amount' => (float) $mono->total_amount,
            'monthly_payment' => (float) ($mono->loanCalculation?->monthly_payment ?? 0),
            'repayment_duration' => (int) $mono->repayment_duration,
            'installments' => $installments,
            'mandate' => $this->directDebitService->formatMandateSummary($mandate),
            'loan_page_path' => $order
                ? '/bnpl-loans/'.$order->id
                : '/bnpl-loans/app-'.$application->id,
            'is_test_loan' => true,
        ];
    }
}
