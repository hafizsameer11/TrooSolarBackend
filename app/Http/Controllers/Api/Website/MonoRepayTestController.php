<?php

namespace App\Http\Controllers\Api\Website;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\UserMonoAccount;
use App\Services\MonoRepayTestBootstrapService;
use App\Support\MonoRepayTestGuard;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MonoRepayTestController extends Controller
{
    public function __construct(
        private readonly MonoRepayTestBootstrapService $bootstrapService
    ) {}

    /**
     * GET /api/bnpl/mono-repay-test/config
     * Public-ish gate: only returns data for whitelisted users when feature enabled.
     */
    public function config()
    {
        try {
            $user = Auth::user();
            MonoRepayTestGuard::assertAccess($user);

            $linked = UserMonoAccount::where('user_id', $user->id)
                ->where('status', 'linked')
                ->first();

            return ResponseHelper::success([
                'enabled' => true,
                'requires_secret' => (string) config('bnpl_mono_repay_test.secret', '') !== '',
                'mono_linked' => (bool) ($linked && $linked->mono_account_id),
                'bank_label' => $linked?->displayLabel(),
                'installment_amount' => (float) config('bnpl_mono_repay_test.installment_amount', 2000),
                'installment_count' => (int) config('bnpl_mono_repay_test.installment_count', 3),
                'down_payment' => (float) config('bnpl_mono_repay_test.down_payment', 1000),
                'due_today' => (bool) config('bnpl_mono_repay_test.due_today', true),
                'bundle_id' => (int) config('bnpl_mono_repay_test.bundle_id', 0),
            ], 'Mono repayment test config');
        } catch (Exception $e) {
            return ResponseHelper::error($e->getMessage(), 403);
        }
    }

    /**
     * GET /api/bnpl/mono-repay-test/status
     */
    public function status(Request $request)
    {
        try {
            MonoRepayTestGuard::assertAccess(Auth::user());
            MonoRepayTestGuard::assertSecret($request);

            $existing = $this->bootstrapService->getStatus(Auth::user());
            if (! $existing) {
                return ResponseHelper::error('No Mono repayment test loan yet. Run bootstrap first.', 404);
            }

            return ResponseHelper::success($existing, 'Mono repayment test status');
        } catch (Exception $e) {
            Log::warning('Mono repay test status: '.$e->getMessage());

            return ResponseHelper::error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/bnpl/mono-repay-test/bootstrap
     * Body: test_secret, force_regenerate (optional)
     */
    public function bootstrap(Request $request)
    {
        try {
            MonoRepayTestGuard::assertAccess(Auth::user());
            MonoRepayTestGuard::assertSecret($request);

            $data = $request->validate([
                'force_regenerate' => 'nullable|boolean',
            ]);

            $result = $this->bootstrapService->bootstrap(
                Auth::user(),
                (bool) ($data['force_regenerate'] ?? false)
            );

            return ResponseHelper::success($result, 'Mono repayment test loan ready');
        } catch (Exception $e) {
            Log::error('Mono repay test bootstrap: '.$e->getMessage());

            return ResponseHelper::error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/bnpl/mono-repay-test/refresh-due-dates
     * Sets pending test installments due today (for artisan collect-due-installments).
     */
    public function refreshDueDates(Request $request)
    {
        try {
            MonoRepayTestGuard::assertAccess(Auth::user());
            MonoRepayTestGuard::assertSecret($request);

            $result = $this->bootstrapService->refreshDueDates(Auth::user());

            return ResponseHelper::success($result, 'Test installment due dates updated to today');
        } catch (Exception $e) {
            Log::error('Mono repay test refresh due dates: '.$e->getMessage());

            return ResponseHelper::error($e->getMessage(), 422);
        }
    }
}
