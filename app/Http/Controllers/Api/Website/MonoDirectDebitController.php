<?php

namespace App\Http\Controllers\Api\Website;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Models\LoanInstallment;
use App\Models\MonoLoanCalculation;
use App\Services\MonoDirectDebitService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MonoDirectDebitController extends Controller
{
    public function __construct(
        private readonly MonoDirectDebitService $directDebitService
    ) {}

    /**
     * GET /api/bnpl/mandate/status/{mono_calculation_id}
     */
    public function status(int $monoCalculationId)
    {
        $mandate = $this->directDebitService->findMandateForCalculation($monoCalculationId, (int) Auth::id());
        if ($mandate) {
            $mandate = $this->directDebitService->syncMandateFromMono($mandate);
        }

        return ResponseHelper::success(
            $this->directDebitService->formatMandateSummary($mandate),
            'Mono Direct Debit mandate status'
        );
    }

    /**
     * POST /api/bnpl/mandate/initiate
     * Body: mono_calculation_id (required), loan_application_id (optional)
     */
    public function initiate(Request $request)
    {
        try {
            $data = $request->validate([
                'mono_calculation_id' => 'required|integer|exists:mono_loan_calculations,id',
                'loan_application_id' => 'nullable|integer|exists:loan_applications,id',
                'customer_address' => 'nullable|string|max:200',
                'customer_phone' => 'nullable|string|max:20',
            ]);

            $mono = MonoLoanCalculation::with('loanCalculation')->findOrFail($data['mono_calculation_id']);
            $calcUserId = $mono->loanCalculation?->user_id;
            if ($calcUserId && (int) $calcUserId !== (int) Auth::id()) {
                return ResponseHelper::error('Unauthorized loan access.', 403);
            }

            if (! empty($data['loan_application_id'])) {
                $app = LoanApplication::where('id', $data['loan_application_id'])
                    ->where('user_id', Auth::id())
                    ->first();
                if (! $app) {
                    return ResponseHelper::error('Loan application not found.', 404);
                }
            }

            $result = $this->directDebitService->initiateMandateForLoan(
                Auth::user(),
                $mono,
                $data['loan_application_id'] ?? null,
                [
                    'customer_address' => $data['customer_address'] ?? null,
                    'customer_phone' => $data['customer_phone'] ?? null,
                ]
            );

            return ResponseHelper::success([
                'mandate' => $this->directDebitService->formatMandateSummary($result['mandate']),
                'authorization_url' => $result['authorization_url'],
            ], 'Mono Direct Debit mandate initiated. Complete authorization in Mono.');
        } catch (Exception $e) {
            Log::error('Mono mandate initiate error: ' . $e->getMessage());

            return ResponseHelper::error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/bnpl/installments/{installmentId}/mono-debit
     */
    public function debitInstallment(int $installmentId)
    {
        try {
            $installment = LoanInstallment::where('id', $installmentId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $debitTx = $this->directDebitService->collectInstallment($installment);

            return ResponseHelper::success([
                'installment_id' => $installment->id,
                'reference' => $debitTx->reference,
                'status' => $debitTx->status,
                'amount' => round($debitTx->amount_kobo / 100, 2),
            ], 'Installment debited successfully from your bank account.');
        } catch (Exception $e) {
            Log::error('Mono installment debit error: ' . $e->getMessage(), [
                'installment_id' => $installmentId,
            ]);

            return ResponseHelper::error($e->getMessage(), 422);
        }
    }
}
