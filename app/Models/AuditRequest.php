<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'audit_type',
        'audit_subtype',
        'customer_type',
        'source',
        'company_name',
        'property_state',
        'property_address',
        'property_landmark',
        'building_type',
        'facility_description',
        'property_floors',
        'property_rooms',
        'contact_name',
        'contact_phone',
        'is_gated_estate',
        'estate_name',
        'estate_address',
        'preferred_audit_date',
        'preferred_audit_time',
        'status',
        'admin_notes',
        'approval_payment_date',
        'approval_payment_time',
        'approval_payment_amount',
        'approval_payment_account_details',
        'customer_has_paid',
        'customer_payment_date',
        'customer_payment_time',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'is_gated_estate' => 'boolean',
        'customer_has_paid' => 'boolean',
        'property_floors' => 'integer',
        'property_rooms' => 'integer',
        'preferred_audit_date' => 'date',
        'approval_payment_date' => 'date',
        'customer_payment_date' => 'date',
        'approval_payment_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
