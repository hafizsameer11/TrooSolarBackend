<?php

namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Models\UserMonoAccount;
use App\Services\MonoService;
use App\Helpers\ResponseHelper;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class UserMonoAccountController extends Controller
{
    /**
     * GET /api/user/mono-account
     */
    public function show()
    {
        $account = UserMonoAccount::where('user_id', Auth::id())
            ->where('status', 'linked')
            ->first();

        return ResponseHelper::success([
            'linked' => $account !== null,
            'mono_account_id' => $account?->mono_account_id,
            'mono_customer_id' => $account?->mono_customer_id,
            'bank_label' => $account?->displayLabel(),
            'bank_name' => $account?->bank_name,
            'account_name' => $account?->account_name,
            'account_number_last4' => $account?->account_number_last4,
            'linked_at' => $account?->linked_at?->toIso8601String(),
        ], 'Mono account status retrieved');
    }

    /**
     * POST /api/user/mono-account/link
     * Link or change the user's Mono bank account (re-link overwrites previous).
     */
    public function link(Request $request, MonoService $monoService)
    {
        try {
            $data = $request->validate([
                'mono_code' => 'required|string',
            ]);

            $accountId = $monoService->exchangeCode($data['mono_code']);

            $monoCustomerId = $monoService->resolveCustomerIdForAccount($accountId);

            $account = UserMonoAccount::updateOrCreate(
                ['user_id' => Auth::id()],
                array_filter([
                    'mono_account_id' => $accountId,
                    'mono_customer_id' => $monoCustomerId,
                    'status' => 'linked',
                    'linked_at' => now(),
                ], fn ($value) => $value !== null)
            );

            return ResponseHelper::success([
                'linked' => true,
                'mono_account_id' => $account->mono_account_id,
                'bank_label' => $account->displayLabel(),
                'linked_at' => $account->linked_at?->toIso8601String(),
            ], 'Bank account linked successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('User Mono account link error: ' . $e->getMessage());

            return ResponseHelper::error('Failed to link bank account: ' . $e->getMessage(), 500);
        }
    }
}
