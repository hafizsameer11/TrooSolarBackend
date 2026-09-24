<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanApplication extends Model
{
    use HasFactory;
    protected $fillable = [
    'prior_application_id',
    'title_document',
    'upload_document',
    'beneficiary_name',
    'beneficiary_email',
    'beneficiary_relationship',
    'beneficiary_phone',
    'status',
    'user_id',
    'mono_loan_calculation',
    'loan_amount',
    'repayment_duration',
    'customer_type',
    'financing_path',
    'financing_partner_id',
    'finance_agreement_accepted_at',
    'product_category',
    'audit_type',
    'property_state',
    'property_address',
    'property_landmark',
    'property_floors',
    'property_rooms',
    'property_status',
    'is_gated_estate',
    'estate_name',
    'estate_address',
    'credit_check_method',
    'mono_account_id',
    'mono_customer_id',
    'mono_credit_status',
    'mono_can_afford',
    'mono_monthly_payment_kobo',
    'mono_credit_report',
    'mono_credit_session_id',
    'bank_statement_path',
    'live_photo_path',
    'social_media_handle',
    'bvn',
    'guarantor_id',
    'admin_notes',
    'counter_offer_min_deposit',
    'counter_offer_min_tenor',
    'partner_offer_interest_rate',
    'partner_offer_initial_deposit',
    'partner_offer_admin_fees',
    'partner_offer_repayment_amount',
    'partner_offer_loan_amount',
    'partner_offer_tenor',
    'partner_offer_documents',
        'order_items_snapshot',
        'loan_plan_snapshot',
        'installation_requested_date',
    'installation_booking_status',
    'installation_rejected_dates',
];

    protected $casts = [
        'order_items_snapshot' => 'array',
        'loan_plan_snapshot' => 'array',
        'installation_rejected_dates' => 'array',
        'mono_credit_report' => 'array',
        'partner_offer_documents' => 'array',
        'mono_can_afford' => 'boolean',
        'finance_agreement_accepted_at' => 'datetime',
    ];

// loan history
public function loanHistories()
{
    return $this->hasMany(LoanHistory::class);
}
public function loanCalculation()
{
    return $this->hasOne(LoanCalculation::class, 'user_id', 'user_id');
}

public function loanStatus()
{
    return $this->hasOne(LoanStatus::class, 'loan_application_id');
}
 public function loan_installments()
 {
     return $this->hasMany(LoanInstallment::class, 'mono_calculation_id', 'mono_loan_calculation');
 }
 public function mono(){
     return $this->hasOne(MonoLoanCalculation::class, 'id', 'mono_loan_calculation');
 }

 public function user()
 {
     return $this->belongsTo(User::class);
 }

 public function priorApplication()
 {
     return $this->belongsTo(self::class, 'prior_application_id');
 }

 public function guarantor()
 {
     return $this->hasOne(Guarantor::class, 'loan_application_id');
 }

 public function financingPartner()
 {
     return $this->belongsTo(Partner::class, 'financing_partner_id');
 }

}