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
