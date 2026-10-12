<?php

namespace App\Http\Controllers;

use App\Models\LoanCalculation;
use App\Models\LoanInstallment;
use App\Models\MonoLoanCalculation;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\InstallmentPaymentRecorder;
// use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InstallmentController extends Controller
{
    public function historyWithCurrentMonth()
{
    $user = Auth::user();
    $now  = now();

    // Define "overdue": not paid AND payment_date strictly before today (at start of day)
    $overdueBase = LoanInstallment::query()
        ->where('user_id', $user->id)
        ->where('status', '!=', LoanInstallment::STATUS_PAID)
        ->whereDate('payment_date', '<', $now->copy()->startOfDay());

    $overdueCount  = (clone $overdueBase)->count();
    $overdueAmount = (clone $overdueBase)->sum('amount'); // adjust if you track partial payments

    // Current month list (annotated with is_overdue)
    $current = LoanInstallment::query()
        ->where('user_id', $user->id)
        ->forMonth($now)
        ->orderBy('payment_date', 'asc')
        ->get()
        ->map(function ($i) use ($now) {
            $mapped = $this->mapInstallment($i);
            $isOverdue = $i->status !== LoanInstallment::STATUS_PAID
                && $i->payment_date?->lt($now->copy()->startOfDay());
            // Add is_overdue without touching your styling/shape from mapInstallment
            return array_merge($mapped, ['is_overdue' => $isOverdue]);
        });

    // History list (all except current month; also annotated with is_overdue)
    // History list: ONLY previous months (strictly before the current month)
$history = LoanInstallment::query()
    ->where('user_id', $user->id)
    ->whereDate('payment_date', '<', $now->copy()->startOfMonth())
    ->orderBy('payment_date', 'desc')
    ->get()
    ->map(function ($i) use ($now) {
        $mapped = $this->mapInstallment($i);
        $isOverdue = $i->status !== LoanInstallment::STATUS_PAID
            && $i->payment_date?->lt($now->copy()->startOfDay());
        return array_merge($mapped, ['is_overdue' => $isOverdue]);
    });


    // Activity/completion flags you already had
    $isActive     = LoanInstallment::where('status', LoanInstallment::STATUS_PAID)
        ->where('user_id', $user->id)
        ->exists();

    $hasUnpaid    = LoanInstallment::where('status', '!=', LoanInstallment::STATUS_PAID)
        ->where('user_id', $user->id)
        ->exists();
        $loanCalculation = LoanCalculation::where('user_id', $user->id)->latest()->first();
        $monoLoanCalculation=MonoLoanCalculation::where('loan_calculation_id', $loanCalculation->id)->latest()->first();

    return response()->json([
        'status' => 'success',
        'data'   => [
            'current_month'  => $current,
            'history'        => $history,
            'isActive'       => $isActive,
            'isCompleted'    => !$hasUnpaid,
            'hasOverdue'     => $overdueCount > 0,
            'overdueCount'   => $overdueCount,
            'overdueAmount'  => (float) $overdueAmount, // cast for clean JSON
            'loan'=>$monoLoanCalculation
        ],
    ]);
}
    public function historyWithCurrentMonthforAdmin($userId)
{
    $user = \App\Models\User::find($userId);
    $now  = now();

    // Define "overdue": not paid AND payment_date strictly before today (at start of day)
    $overdueBase = LoanInstallment::query()
        ->where('user_id', $user->id)
        ->where('status', '!=', LoanInstallment::STATUS_PAID)
        ->whereDate('payment_date', '<', $now->copy()->startOfDay());

    $overdueCount  = (clone $overdueBase)->count();
    $overdueAmount = (clone $overdueBase)->sum('amount'); // adjust if you track partial payments

    // Current month list (annotated with is_overdue)
    $current = LoanInstallment::query()
        ->where('user_id', $user->id)
        ->forMonth($now)
        ->orderBy('payment_date', 'asc')
        ->get()
        ->map(function ($i) use ($now) {
            $mapped = $this->mapInstallment($i);
            $isOverdue = $i->status !== LoanInstallment::STATUS_PAID
                && $i->payment_date?->lt($now->copy()->startOfDay());
            // Add is_overdue without touching your styling/shape from mapInstallment
            return array_merge($mapped, ['is_overdue' => $isOverdue]);
        });

    // History list (all except current month; also annotated with is_overdue)
    // History list: ONLY previous months (strictly before the current month)
$history = LoanInstallment::query()
    ->where('user_id', $user->id)
    ->whereDate('payment_date', '<', $now->copy()->startOfMonth())
    ->orderBy('payment_date', 'desc')
    ->get()
    ->map(function ($i) use ($now) {
        $mapped = $this->mapInstallment($i);
        $isOverdue = $i->status !== LoanInstallment::STATUS_PAID
            && $i->payment_date?->lt($now->copy()->startOfDay());
        return array_merge($mapped, ['is_overdue' => $isOverdue]);
    });


    // Activity/completion flags you already had
    $isActive     = LoanInstallment::where('status', LoanInstallment::STATUS_PAID)
        ->where('user_id', $user->id)
        ->exists();

    $hasUnpaid    = LoanInstallment::where('status', '!=', LoanInstallment::STATUS_PAID)
        ->where('user_id', $user->id)
        ->exists();
        $loanCalculation = LoanCalculation::where('user_id', $user->id)->latest()->first();
        $monoLoanCalculation=MonoLoanCalculation::where('loan_calculation_id', $loanCalculation->id)->latest()->first();

    return response()->json([
        'status' => 'success',
        'data'   => [
            'current_month'  => $current,
            'history'        => $history,
            'isActive'       => $isActive,
            'isCompleted'    => !$hasUnpaid,
            'hasOverdue'     => $overdueCount > 0,
            'overdueCount'   => $overdueCount,
            'overdueAmount'  => (float) $overdueAmount, // cast for clean JSON
            'loan'=>$monoLoanCalculation
        ],
    ]);
}


    /**
     * Pay a single installment.
     * - method = 'wallet' → type = 'shop' | 'loan' required → deduct from respective balance
     * - method != 'wallet' → require tx_id from FE
     */
 public function payInstallment(Request $request, int $installmentId)
{
    // ✅ 1) Validate & return JSON errors if invalid
    $validator = Validator::make($request->all(), [
        'method'    => ['required','string','max:50', Rule::in(['wallet','bank','card','transfer'])],
        'type'      => ['nullable','string'],                   // only for wallet
        'tx_id'     => ['nullable','string','max:255'],                                    // required if NOT wallet
        'reference' => ['nullable','string','max:255'],
        'title'     => ['nullable','string','max:255'],
    ], [
        'method.required' => 'Payment method is required.',
        'method.in'       => 'Method must be one of: wallet, bank, card, transfer.',
        'type.in'         => 'Type must be one of: shop, loan.',
    ]);

    // Extra conditional rules
    $validator->after(function ($v) use ($request) {
        $method = $request->input('method');

        if ($method === 'wallet') {
            if (!in_array($request->input('type'), ['shop','loan'], true)) {
                $v->errors()->add('type', 'type is required and must be one of: shop, loan when method=wallet');
            }
        } else {
            if (!$request->filled('tx_id')) {
                $v->errors()->add('tx_id', 'tx_id is required for non-wallet methods');
            }
        }
    });

    if ($validator->fails()) {
        return response()->json([
            'status'  => 'error',
            'message' => 'Validation failed.',
            'errors'  => $validator->errors(), // <-- Laravel MessageBag, perfect for FE
        ], 422);
    }

    $data = $validator->validated();
    $user = Auth::user();

    // ✅ 2) Proceed with the payment in a single DB transaction
    return DB::transaction(function () use ($user, $data, $installmentId) {
        // Lock installment row
        $inst = LoanInstallment::where('id', $installmentId)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($inst->status === LoanInstallment::STATUS_PAID) {
            return response()->json([
                'status'  => 'success',
                'message' => 'Installment already paid',
                'data'    => $this->mapInstallment($inst),
            ]);
        }

        $amount    = (float) $inst->amount;
        $method    = $data['method'];
        $type      = $data['type'] ?? null;  // shop|loan (wallet only)
        $title     = $data['title'] ?? 'Loan installment payment';
        $reference = $data['reference'] ?? ('INSTALLMENT#'.$inst->id);

        $txPayload = [
            'title'         => $title,
            'amount'        => $amount,
            'status'        => 'success',   // or 'pending' if you have async gateways
            'type'          => 'debit',     // money going out of user
            'method'        => $method,
            'transacted_at' => now(),
            'user_id'       => $user->id,
            'tx_id'         => null,
            'reference'     => $reference,
        ];

        if ($method === 'wallet') {
            // Require & deduct from wallet (shop/loan)
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$wallet) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Wallet not found',
                ], 404);
            }

            if ($type === 'shop') {
                if ((float)$wallet->shop_balance < $amount) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Insufficient shop balance',
                        'errors'  => ['shop_balance' => ['Insufficient balance']],
                    ], 422);
                }
                $wallet->shop_balance = (float)$wallet->shop_balance - $amount;
                $txPayload['tx_id']   = 'WALLET-SHOP-'.now()->timestamp.'-'.$inst->id;

            } elseif ($type === 'loan') {
                if ((float)$wallet->loan_balance < $amount) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Insufficient loan balance',
                        'errors'  => ['loan_balance' => ['Insufficient balance']],
                    ], 422);
                }
                $wallet->loan_balance = (float)$wallet->loan_balance - $amount;
                $txPayload['tx_id']   = 'WALLET-LOAN-'.now()->timestamp.'-'.$inst->id;
            }

            $wallet->save();

        } else {
            // Non-wallet → must have tx_id from FE (validated above)
            $txPayload['tx_id'] = $data['tx_id'];
        }

        // Create & link transaction
        $transaction = Transaction::create($txPayload);

        $inst->update([
            'status'         => LoanInstallment::STATUS_PAID,
            'paid_at'        => now(),
            'transaction_id' => $transaction->id,
        ]);

        app(InstallmentPaymentRecorder::class)
            ->syncOrderPaymentCompleteForMono($inst->mono_calculation_id);

        $inst->load('transaction');

        return response()->json([
            'status'  => 'success',
            'message' => 'Installment paid successfully',
            'data'    => $this->mapInstallment($inst),
        ]);
    });
}

    /** Map installment for FE */
    private function mapInstallment(LoanInstallment $i): array
    {
        $due = optional($i->payment_date)?->toDateString();

        return [
            'id'                  => $i->id,
            'mono_calculation_id' => $i->mono_calculation_id,
            'amount'              => (float) $i->amount,
            'installment_number'  => $i->installment_number,
            'payment_date'        => $due,
            'due_date'            => $due,
            'status'              => $i->status,
            'computed_status'     => $i->computed_status,
            'paid_at'             => optional($i->paid_at)?->toDateTimeString(),
            'remaining_duration'  => $i->remaining_duration,
            'transaction'         => $i->transaction ? [
                'id'         => $i->transaction->id,
                'tx_id'      => $i->transaction->tx_id,
                'method'     => $i->transaction->method,
                'type'       => $i->transaction->type,
                'status'     => $i->transaction->status,
                'amount'     => (float) $i->transaction->amount,
                'reference'  => $i->transaction->reference,
                'transacted_at' => optional($i->transaction->transacted_at)?->toDateTimeString(),
            ] : null,
        ];
    }
}
