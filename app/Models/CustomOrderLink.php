<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class CustomOrderLink extends Model
{
    protected $fillable = [
        'user_id',
        'audit_request_id',
        'token',
        'order_type',
        'items',
        'custom_items',
        'created_by',
    ];

    protected $casts = [
        'items' => 'array',
        'custom_items' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class, 'audit_request_id');
    }

    /**
     * Hydrate snapshot rows into cart-item shaped objects for API / email.
     * Isolated from the user's live shop cart.
     *
     * @return Collection<int, object>
     */
    public function resolveCartItems(): Collection
    {
        $rows = collect($this->items ?? []);
        if ($rows->isEmpty()) {
            return collect();
        }

        $productIds = $rows->where('type', 'product')->pluck('id')->filter()->unique()->values()->all();
        $bundleIds = $rows->where('type', 'bundle')->pluck('id')->filter()->unique()->values()->all();

        $products = $productIds
            ? Product::with('images')->whereIn('id', $productIds)->get()->keyBy('id')
            : collect();
        $bundles = $bundleIds
            ? Bundles::whereIn('id', $bundleIds)->get()->keyBy('id')
            : collect();

        return $rows->values()->map(function (array $row, int $index) use ($products, $bundles) {
            $type = ($row['type'] ?? '') === 'bundle' ? 'bundle' : 'product';
            $id = (int) ($row['id'] ?? 0);
            $qty = max(1, (int) ($row['quantity'] ?? 1));
            $unitPrice = (float) ($row['unit_price'] ?? 0);
            $subtotal = (float) ($row['subtotal'] ?? ($unitPrice * $qty));
            $itemable = $type === 'bundle' ? $bundles->get($id) : $products->get($id);

            return (object) [
                'id' => $this->id * 1000 + $index + 1,
                'user_id' => $this->user_id,
                'itemable_id' => $id,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'type' => $type,
                'itemable' => $itemable,
            ];
        })->filter(fn ($item) => $item->itemable !== null)->values();
    }
}
