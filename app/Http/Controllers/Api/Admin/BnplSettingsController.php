<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\BnplSettings;
use App\Support\BnplCreditCheckMethodCopy;
use App\Support\BnplFinanceAgreement;
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
                'finance_agreement' => BnplFinanceAgreement::fromSettings($settings),
                'credit_check_method' => BnplCreditCheckMethodCopy::fromSettings($settings),
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
                'financing_path_title' => 'nullable|string|max:255',
                'financing_path_back_label' => 'nullable|string|max:100',
                'financing_path_continue_troosolar_label' => 'nullable|string|max:255',
                'financing_path_continue_partner_label' => 'nullable|string|max:255',
                'financing_path_troosolar_enabled' => 'nullable|boolean',
                'financing_path_partner_enabled' => 'nullable|boolean',
                'financing_path_unavailable_label' => 'nullable|string|max:255',
                'finance_agreement_modal_title' => 'nullable|string|max:255',
                'finance_agreement_checkbox_prefix' => 'nullable|string|max:255',
                'finance_agreement_link_label' => 'nullable|string|max:255',
                'finance_agreement_close_label' => 'nullable|string|max:100',
                'finance_agreement_accept_label' => 'nullable|string|max:100',
                'finance_agreement_residential_text' => 'nullable|string|max:50000',
                'finance_agreement_sme_text' => 'nullable|string|max:50000',
                'credit_check_intro' => 'nullable|string|max:2000',
                'credit_check_continue_label' => 'nullable|string|max:100',
                'credit_check_unavailable_label' => 'nullable|string|max:255',
                'credit_check_residential_auto_enabled' => 'nullable|boolean',
                'credit_check_residential_auto_title' => 'nullable|string|max:255',
                'credit_check_residential_auto_description' => 'nullable|string|max:2000',
                'credit_check_residential_manual_enabled' => 'nullable|boolean',
                'credit_check_residential_manual_title' => 'nullable|string|max:255',
                'credit_check_residential_manual_description' => 'nullable|string|max:2000',
                'credit_check_sme_auto_enabled' => 'nullable|boolean',
                'credit_check_sme_auto_title' => 'nullable|string|max:255',
                'credit_check_sme_auto_description' => 'nullable|string|max:2000',
                'credit_check_sme_manual_enabled' => 'nullable|boolean',
                'credit_check_sme_manual_title' => 'nullable|string|max:255',
                'credit_check_sme_manual_description' => 'nullable|string|max:2000',
                'credit_check_partner_fee_title' => 'nullable|string|max:255',
                'credit_check_partner_fee_intro' => 'nullable|string|max:2000',
                'credit_check_partner_success_message' => 'nullable|string|max:2000',
                'credit_check_partner_routed_note' => 'nullable|string|max:2000',
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
                'financing_path_title',
                'financing_path_back_label',
                'financing_path_continue_troosolar_label',
                'financing_path_continue_partner_label',
                'financing_path_unavailable_label',
                'finance_agreement_modal_title',
                'finance_agreement_checkbox_prefix',
                'finance_agreement_link_label',
                'finance_agreement_close_label',
                'finance_agreement_accept_label',
                'finance_agreement_residential_text',
                'finance_agreement_sme_text',
                'credit_check_intro',
                'credit_check_continue_label',
                'credit_check_unavailable_label',
                'credit_check_residential_auto_title',
                'credit_check_residential_auto_description',
                'credit_check_residential_manual_title',
                'credit_check_residential_manual_description',
                'credit_check_sme_auto_title',
                'credit_check_sme_auto_description',
                'credit_check_sme_manual_title',
                'credit_check_sme_manual_description',
                'credit_check_partner_fee_title',
                'credit_check_partner_fee_intro',
                'credit_check_partner_success_message',
                'credit_check_partner_routed_note',
            ] as $field) {
                // exists() so empty strings still clear/update (has() treats "" as missing)
                if ($request->exists($field)) {
                    $settings->{$field} = $request->input($field);
                }
            }

            if ($request->has('financing_path_troosolar_enabled')) {
                $settings->financing_path_troosolar_enabled = filter_var(
                    $request->input('financing_path_troosolar_enabled'),
                    FILTER_VALIDATE_BOOLEAN
                );
            }
            if ($request->has('financing_path_partner_enabled')) {
                $settings->financing_path_partner_enabled = filter_var(
                    $request->input('financing_path_partner_enabled'),
                    FILTER_VALIDATE_BOOLEAN
                );
            }

            foreach ([
                'credit_check_residential_auto_enabled',
                'credit_check_residential_manual_enabled',
                'credit_check_sme_auto_enabled',
                'credit_check_sme_manual_enabled',
            ] as $boolField) {
                if ($request->exists($boolField)) {
                    $settings->{$boolField} = filter_var($request->input($boolField), FILTER_VALIDATE_BOOLEAN);
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
                'finance_agreement' => BnplFinanceAgreement::fromSettings($settings),
                'credit_check_method' => BnplCreditCheckMethodCopy::fromSettings($settings),
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
