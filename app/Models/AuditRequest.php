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
        'product_category',
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
        'customer_payment_receipt_path',
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

    /**
     * Map audit fields to Buy Now customer_type (residential|sme|commercial).
     */
    public function resolvedCustomerType(): ?string
    {
        $raw = strtolower(trim((string) ($this->customer_type ?? '')));
        if (in_array($raw, ['residential', 'sme', 'commercial'], true)) {
            return $raw;
        }

        $auditType = strtolower(trim((string) ($this->audit_type ?? '')));
        if ($auditType === 'commercial') {
            return 'commercial';
        }
        if ($auditType === 'home-office' || $auditType === 'home' || $auditType === 'office') {
            return 'residential';
        }

        return $raw !== '' ? $raw : null;
    }

    /**
     * Compact payload for cart-access / Buy Now preload / admin order detail.
     *
     * @return array<string, mixed>
     */
    public function toBuyNowContext(): array
    {
        return [
            'id' => $this->id,
            'customer_type' => $this->resolvedCustomerType(),
            'audit_type' => $this->audit_type,
            'audit_subtype' => $this->audit_subtype,
            'product_category' => $this->product_category,
            'company_name' => $this->company_name,
            'property_address' => $this->property_address,
            'property_state' => $this->property_state,
            'property_floors' => $this->property_floors,
            'property_rooms' => $this->property_rooms,
            'is_gated_estate' => $this->is_gated_estate,
            'estate_name' => $this->estate_name,
            'estate_address' => $this->estate_address,
            'preferred_audit_date' => optional($this->preferred_audit_date)?->format('Y-m-d'),
            'preferred_audit_time' => $this->preferred_audit_time,
            'contact_name' => $this->contact_name,
            'contact_phone' => $this->contact_phone,
            'status' => $this->status,
        ];
    }

    public static function latestForUser(int $userId): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();
    }
}
