<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\BnplSettings;
use App\Support\BnplFinancingPathCopy;
use App\Support\BnplTermsGate;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BnplSettingsController extends Controller
{
    /**
     * Get BNPL global settings (single row).
     * GET /api/admin/bnpl/settings
     */
    public function show()
    {
        try {
            $settings = BnplSettings::get();
            $downPaymentOptions = is_array($settings->down_payment_options)
                ? array_values(array_filter($settings->down_payment_options, fn($v) => is_numeric($v)))
                : [];
            sort($downPaymentOptions);
            if (empty($downPaymentOptions)) {
                $downPaymentOptions = [(float) $settings->min_down_percentage];
            }
            return ResponseHelper::success([
                'interest_rate_percentage' => (float) $settings->interest_rate_percentage,
                'min_down_percentage' => (float) $settings->min_down_percentage,
                'down_payment_options' => $downPaymentOptions,
                'management_fee_percentage' => (float) $settings->management_fee_percentage,
                'legal_fee_percentage' => (float) $settings->legal_fee_percentage,
                'insurance_fee_percentage' => (float) $settings->insurance_fee_percentage,
                'minimum_loan_amount' => (float) $settings->minimum_loan_amount,
                'credit_check_fee' => (float) ($settings->credit_check_fee ?? 1000),
                'loan_durations' => $settings->loan_durations ?? [3, 6, 9, 12],
                'terms_gate' => BnplTermsGate::fromSettings($settings),
                'financing_path' => BnplFinancingPathCopy::fromSettings($settings),
            ], 'BNPL settings retrieved successfully');
        } catch (Exception $e) {
            Log::error('BNPL Settings Show Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to retrieve BNPL settings', 500);
        }
    }

    /**
     * Update BNPL global settings.
     * PUT /api/admin/bnpl/settings
     */
    public function update(Request $request)
    {
        try {
            $request->validate([
                'interest_rate_percentage' => 'nullable|numeric|min:0|max:100',
                'min_down_percentage' => 'nullable|numeric|min:0|max:100',
                'down_payment_options' => 'nullable|array',
                'down_payment_options.*' => 'numeric|min:0|max:100',
                'management_fee_percentage' => 'nullable|numeric|min:0|max:100',
                'legal_fee_percentage' => 'nullable|numeric|min:0|max:100',
                'insurance_fee_percentage' => 'nullable|numeric|min:0|max:100',
                'minimum_loan_amount' => 'nullable|numeric|min:0',
                'credit_check_fee' => 'nullable|numeric|min:0',
                'loan_durations' => 'nullable|array',
                'loan_durations.*' => 'integer|min:1|max:120',
                'terms_gate_title' => 'nullable|string|max:255',
                'terms_gate_subtitle' => 'nullable|string|max:2000',
                'terms_gate_checkbox_prefix' => 'nullable|string|max:255',
                'terms_gate_terms_label' => 'nullable|string|max:255',
                'terms_gate_privacy_label' => 'nullable|string|max:255',
                'terms_gate_proceed_label' => 'nullable|string|max:100',
                'terms_of_service_url' => 'nullable|string|max:500',
                'terms_privacy_policy_url' => 'nullable|string|max:500',
                'financing_path_intro' => 'nullable|string|max:2000',
                'financing_path_troosolar_title' => 'nullable|string|max:255',
                'financing_path_troosolar_description' => 'nullable|string|max:2000',
                'financing_path_partner_title' => 'nullable|string|max:255',
                'financing_path_partner_description' => 'nullable|string|max:2000',
            ]);

            $settings = BnplSettings::get();

            if ($request->has('interest_rate_percentage')) {
                $settings->interest_rate_percentage = $request->interest_rate_percentage;
            }
            if ($request->has('min_down_percentage')) {
                $settings->min_down_percentage = $request->min_down_percentage;
            }
            if ($request->has('down_payment_options')) {
                $downOptions = collect($request->down_payment_options)
                    ->filter(fn($v) => is_numeric($v))
                    ->map(fn($v) => (float) $v)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $settings->down_payment_options = $downOptions;
                if (!empty($downOptions)) {
                    $settings->min_down_percentage = min($downOptions);
                }
            }
            if ($request->has('management_fee_percentage')) {
                $settings->management_fee_percentage = $request->management_fee_percentage;
            }
            if ($request->has('legal_fee_percentage')) {
                $settings->legal_fee_percentage = $request->legal_fee_percentage;
            }
            if ($request->has('insurance_fee_percentage')) {
                $settings->insurance_fee_percentage = $request->insurance_fee_percentage;
            }
            if ($request->has('minimum_loan_amount')) {
                $settings->minimum_loan_amount = $request->minimum_loan_amount;
            }
            if ($request->has('credit_check_fee')) {
                $settings->credit_check_fee = $request->credit_check_fee;
            }
            if ($request->has('loan_durations')) {
                $durations = $request->loan_durations;
                sort($durations);
                $settings->loan_durations = array_values(array_unique($durations));
            }

            foreach ([
                'terms_gate_title',
                'terms_gate_subtitle',
                'terms_gate_checkbox_prefix',
                'terms_gate_terms_label',
                'terms_gate_privacy_label',
                'terms_gate_proceed_label',
                'terms_of_service_url',
                'terms_privacy_policy_url',
                'financing_path_intro',
                'financing_path_troosolar_title',
                'financing_path_troosolar_description',
                'financing_path_partner_title',
                'financing_path_partner_description',
            ] as $field) {
                if ($request->has($field)) {
                    $settings->{$field} = $request->input($field);
                }
            }

            $settings->save();

            $downPaymentOptions = is_array($settings->down_payment_options)
                ? array_values(array_filter($settings->down_payment_options, fn($v) => is_numeric($v)))
                : [];
            sort($downPaymentOptions);
            if (empty($downPaymentOptions)) {
                $downPaymentOptions = [(float) $settings->min_down_percentage];
            }

            return ResponseHelper::success([
                'interest_rate_percentage' => (float) $settings->interest_rate_percentage,
                'min_down_percentage' => (float) $settings->min_down_percentage,
                'down_payment_options' => $downPaymentOptions,
                'management_fee_percentage' => (float) $settings->management_fee_percentage,
                'legal_fee_percentage' => (float) $settings->legal_fee_percentage,
                'insurance_fee_percentage' => (float) $settings->insurance_fee_percentage,
                'minimum_loan_amount' => (float) $settings->minimum_loan_amount,
                'credit_check_fee' => (float) ($settings->credit_check_fee ?? 1000),
                'loan_durations' => $settings->loan_durations ?? [3, 6, 9, 12],
                'terms_gate' => BnplTermsGate::fromSettings($settings),
                'financing_path' => BnplFinancingPathCopy::fromSettings($settings),
            ], 'BNPL settings updated successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            Log::error('BNPL Settings Update Error: ' . $e->getMessage());
            return ResponseHelper::error('Failed to update BNPL settings', 500);
        }
    }
}
