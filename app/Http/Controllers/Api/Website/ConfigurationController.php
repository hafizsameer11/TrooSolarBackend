<?php

namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Helpers\ResponseHelper;
use App\Models\CheckoutSetting;
use App\Models\State;
use App\Support\CheckoutPricing;
use Illuminate\Http\Request;

class ConfigurationController extends Controller
{
    /**
     * Get customer types for BNPL/Buy Now flow
     */
    public function getCustomerTypes()
    {
        $customerTypes = [
            ['id' => 'residential', 'label' => 'For Residential'],
            ['id' => 'sme', 'label' => 'For SMEs'],
            ['id' => 'commercial', 'label' => 'For Commercial and Industrial'],
        ];

        return ResponseHelper::success($customerTypes, 'Customer types retrieved successfully');
    }

    /**
     * Get audit types for professional audit flow
     */
    public function getAuditTypes()
    {
        $auditTypes = [
            ['id' => 'home-office', 'label' => 'Home / Office'],
            ['id' => 'commercial', 'label' => 'Commercial / Industrial'],
        ];

        return ResponseHelper::success($auditTypes, 'Audit types retrieved successfully');
    }

    /**
     * Get all active states
     */
    public function getStates()
    {
        try {
            $states = State::where('is_active', true)
                ->select('id', 'name', 'code', 'default_delivery_fee', 'default_installation_fee')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(function ($state) {
                    return [
                        'id' => $state->id,
                        'name' => $state->name,
                        'code' => $state->code,
                        'default_delivery_fee' => (float) ($state->default_delivery_fee ?? 0),
                        'default_installation_fee' => (float) ($state->default_installation_fee ?? 0),
                    ];
                });

            return ResponseHelper::success($states, 'States retrieved successfully');
        } catch (\Exception $e) {
            // If states table doesn't exist or has no data, return empty array
            return ResponseHelper::success([], 'No states available');
        }
    }

    /**
     * Get loan configuration for calculator
     * GET /api/config/loan-configuration
     */
    public function getLoanConfiguration()
    {
        try {
            $config = \App\Models\LoanConfiguration::where('is_active', true)->first();
            $bnplSettings = \App\Models\BnplSettings::get();

            $defaultPayload = [
                'minimum_loan_amount' => 1500000,
                'equity_contribution_min' => 30,
                'equity_contribution_max' => 80,
                'down_payment_options' => [30, 40, 50, 60, 70, 80],
                'interest_rate_min' => 4,
                'interest_rate_max' => 4,
                'management_fee_percentage' => 1.0,
                'residual_fee_percentage' => 1.0,
                'insurance_fee_percentage' => 0.5,
                'credit_check_fee' => 1000,
                'repayment_tenor_min' => 1,
                'repayment_tenor_max' => 12,
                'loan_durations' => [3, 6, 9, 12],
            ];

            $payload = $defaultPayload;

            // Base from loan_configuration when present
            if ($config) {
                $payload = [
                    'minimum_loan_amount' => (float) $config->minimum_loan_amount,
                    'equity_contribution_min' => (float) $config->equity_contribution_min,
                    'equity_contribution_max' => (float) $config->equity_contribution_max,
                    'interest_rate_min' => (float) $config->interest_rate_min,
                    'interest_rate_max' => (float) $config->interest_rate_max,
                    'management_fee_percentage' => (float) $config->management_fee_percentage,
                    'residual_fee_percentage' => (float) $config->residual_fee_percentage,
                    'insurance_fee_percentage' => (float) $config->insurance_fee_percentage,
                    'repayment_tenor_min' => (int) $config->repayment_tenor_min,
                    'repayment_tenor_max' => (int) $config->repayment_tenor_max,
                ];
            }

            // Override with BNPL admin settings (source of truth from Admin > BNPL Loan Settings)
            if ($bnplSettings) {
                $loanDurations = is_array($bnplSettings->loan_durations)
                    ? array_values(array_filter($bnplSettings->loan_durations, fn($d) => is_numeric($d) && (int) $d > 0))
                    : [];
                sort($loanDurations);

                $interest = (float) ($bnplSettings->interest_rate_percentage ?? $payload['interest_rate_max']);
                $downPaymentOptions = is_array($bnplSettings->down_payment_options)
                    ? array_values(array_filter($bnplSettings->down_payment_options, fn($d) => is_numeric($d)))
                    : [];
                $downPaymentOptions = array_values(array_unique(array_map('floatval', $downPaymentOptions)));
                sort($downPaymentOptions);
                if (empty($downPaymentOptions)) {
                    $downPaymentOptions = [(float) ($bnplSettings->min_down_percentage ?? $payload['equity_contribution_min'])];
                }

                $payload['minimum_loan_amount'] = (float) ($bnplSettings->minimum_loan_amount ?? $payload['minimum_loan_amount']);
                $payload['equity_contribution_min'] = (float) min($downPaymentOptions);
                $payload['equity_contribution_max'] = (float) max($downPaymentOptions);
                $payload['down_payment_options'] = $downPaymentOptions;
                $payload['interest_rate_min'] = $interest;
                $payload['interest_rate_max'] = $interest;
                $payload['management_fee_percentage'] = (float) ($bnplSettings->management_fee_percentage ?? $payload['management_fee_percentage']);
                $payload['residual_fee_percentage'] = (float) ($bnplSettings->legal_fee_percentage ?? $payload['residual_fee_percentage']);
                $payload['insurance_fee_percentage'] = (float) ($bnplSettings->insurance_fee_percentage ?? $payload['insurance_fee_percentage']);
                $payload['credit_check_fee'] = (float) ($bnplSettings->credit_check_fee ?? $payload['credit_check_fee']);
                if (!empty($loanDurations)) {
                    $payload['repayment_tenor_min'] = (int) min($loanDurations);
                    $payload['repayment_tenor_max'] = (int) max($loanDurations);
                    $payload['loan_durations'] = $loanDurations;
                }
            }

            return ResponseHelper::success($payload, 'Loan configuration retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error('Failed to retrieve loan configuration', 500);
        }
    }

    /**
     * Checkout fees for Buy Now / BNPL (public).
     * GET /api/config/checkout-settings
     */
    public function getCheckoutSettings()
    {
        try {
            $s = CheckoutSetting::get(CheckoutSetting::CHANNEL_BUY_NOW);
            $window = CheckoutPricing::deliveryWindow($s);

            return ResponseHelper::success([
                'delivery_fee' => (int) $s->delivery_fee,
                'category_delivery_fees' => $s->normalizedCategoryDeliveryFees(),
                'category_installation_fees' => $s->normalizedCategoryInstallationFees(),
                'category_materials_fees' => $s->normalizedCategoryMaterialsFees(),
                'category_inspection_fees' => $s->normalizedCategoryInspectionFees(),
                'product_categories' => CheckoutSetting::productCategoryDefinitions(),
                'vat_percentage' => (float) ($s->vat_percentage ?? config('checkout.vat_percentage', 7.5)),
                'insurance_fee_percentage' => (float) ($s->insurance_fee_percentage ?? config('checkout.insurance_fee_percentage', 3)),
                'installation_flat_addon' => (int) ($s->installation_flat_addon ?? 0),
                'installation_materials_cost' => (int) ($s->installation_materials_cost ?? 0),
                'delivery_estimate_label' => $window['label'],
                'delivery_estimated_from' => $window['estimated_from'],
                'delivery_estimated_to' => $window['estimated_to'],
            ], 'Checkout settings retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error('Failed to retrieve checkout settings', 500);
        }
    }

    /**
     * Get add-ons
     * GET /api/config/add-ons
     */
    public function getAddOns()
    {
        try {
            $addOns = \App\Models\AddOn::where('is_active', true)
                ->select('id', 'title', 'description', 'price', 'is_compulsory')
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get();

            return ResponseHelper::success($addOns, 'Add-ons retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::success([], 'No add-ons available');
        }
    }

    /**
     * Get delivery locations by state
     * GET /api/config/delivery-locations?state_id={id}
     */
    public function getDeliveryLocations(Request $request)
    {
        try {
            $stateId = $request->query('state_id');
            
            $query = \App\Models\DeliveryLocation::where('is_active', true);
            
            if ($stateId) {
                $query->where('state_id', $stateId);
            }

            $locations = $query->select('id', 'name', 'state_id', 'delivery_fee', 'installation_fee')
                ->orderBy('name')
                ->get();

            return ResponseHelper::success($locations, 'Delivery locations retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::success([], 'No delivery locations available');
        }
    }

    /**
     * Get calculator settings for inverter selection + solar savings calculator.
     * GET /api/config/calculator-settings
     */
    public function getCalculatorSettings()
    {
        try {
            $defaults = \App\Models\CalculatorSetting::defaults();
            $settings = \App\Models\CalculatorSetting::where('is_active', true)->first();

            if (!$settings) {
                return ResponseHelper::success($defaults, 'Calculator settings retrieved successfully (defaults)');
            }

            return ResponseHelper::success([
                'inverter_ranges' => $settings->inverter_ranges ?: $defaults['inverter_ranges'],
                'solar_savings_profiles' => $settings->solar_savings_profiles ?: $defaults['solar_savings_profiles'],
                'bundle_types' => $settings->bundle_types ?: $defaults['bundle_types'],
                'solar_maintenance_5_years' => (float) ($settings->solar_maintenance_5_years ?? $defaults['solar_maintenance_5_years']),
            ], 'Calculator settings retrieved successfully');
        } catch (\Exception $e) {
            return ResponseHelper::error('Failed to retrieve calculator settings', 500);
        }
    }

    /**
     * Mono Connect public configuration for BNPL credit check.
     * GET /api/config/mono
     */
    public function getMonoConfig()
    {
        return ResponseHelper::success([
            'public_key' => (string) config('services.mono.public_key', ''),
            'env' => (string) config('services.mono.env', 'sandbox'),
        ], 'Mono configuration retrieved successfully');
    }
}
