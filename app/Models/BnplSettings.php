<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BnplSettings extends Model
{
    protected $table = 'bnpl_settings';

    protected $fillable = [
        'interest_rate_percentage',
        'min_down_percentage',
        'down_payment_options',
        'management_fee_percentage',
        'legal_fee_percentage',
        'insurance_fee_percentage',
        'minimum_loan_amount',
        'credit_check_fee',
        'loan_durations',
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
        'financing_path_troosolar_enabled',
        'financing_path_partner_enabled',
        'financing_path_unavailable_label',
        'finance_agreement_modal_title',
        'finance_agreement_checkbox_prefix',
        'finance_agreement_link_label',
        'finance_agreement_close_label',
        'finance_agreement_accept_label',
        'finance_agreement_residential_text',
        'finance_agreement_sme_text',
    ];

    protected $casts = [
        'interest_rate_percentage' => 'decimal:2',
        'min_down_percentage' => 'decimal:2',
        'down_payment_options' => 'array',
        'management_fee_percentage' => 'decimal:2',
        'legal_fee_percentage' => 'decimal:2',
        'insurance_fee_percentage' => 'decimal:2',
        'minimum_loan_amount' => 'decimal:2',
        'credit_check_fee' => 'decimal:2',
        'loan_durations' => 'array',
        'financing_path_troosolar_enabled' => 'boolean',
        'financing_path_partner_enabled' => 'boolean',
    ];

    /**
     * Get the single BNPL settings row (singleton).
     */
    public static function get(): self
    {
        $row = self::first();
        if (!$row) {
            $row = self::create([
                'interest_rate_percentage' => 4,
                'min_down_percentage' => 30,
                'down_payment_options' => [30, 40, 50, 60, 70, 80],
                'management_fee_percentage' => 1,
                'legal_fee_percentage' => 0,
                'insurance_fee_percentage' => 0.5,
                'minimum_loan_amount' => 0,
                'credit_check_fee' => 1000,
                'loan_durations' => [3, 6, 9, 12],
            ]);
        }
        return $row;
    }
}
