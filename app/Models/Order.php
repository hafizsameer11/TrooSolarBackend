<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\LoanApplication;

class Order extends Model
{
    use HasFactory;
     protected $fillable = [
        'user_id',
        'delivery_address_id',
        'order_number',
        'total_price',
        'payment_method',
        'payment_status',
        'order_status',
        'note',
        'installation_price',
        'mono_calculation_id',
        'product_id',   // ⬅️ new
        'bundle_id',    // ⬅️ new
        'material_cost',
        'delivery_fee',
        'inspection_fee',
        'insurance_fee',
        'vat_amount',
        'order_type',
        'customer_type',
        'installer_choice',
        'property_floors',
        'property_rooms',
        'is_gated_estate',
        'estate_name',
        'estate_address',
        'product_price',
        'audit_request_id',
        'installation_requested_date',
        'estimated_delivery_from',
        'estimated_delivery_to',
        'delivery_estimate_label',
        'include_installation',
    ];

    protected $casts = [
        'installation_requested_date' => 'date',
        'estimated_delivery_from' => 'date',
        'estimated_delivery_to' => 'date',
        'include_installation' => 'boolean',
        'is_gated_estate' => 'boolean',
        'property_floors' => 'integer',
        'property_rooms' => 'integer',
        'vat_amount' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class);
    }

      public function bundle()
    {
        return $this->belongsTo(\App\Models\Bundles::class, 'bundle_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveryAddress()
    {
        return $this->belongsTo(DeliveryAddress::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function monoCalculation() {
    return $this->belongsTo(MonoLoanCalculation::class, 'mono_calculation_id');
}

    public function loanApplication()
    {
        return $this->hasOne(LoanApplication::class, 'mono_loan_calculation', 'mono_calculation_id');
    }

    public function auditRequest()
    {
        return $this->belongsTo(AuditRequest::class);
}
}