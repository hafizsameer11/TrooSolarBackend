<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutSetting extends Model
{
    protected $table = 'checkout_settings';

    protected $fillable = [
        'delivery_fee',
        'category_delivery_fees',
        'category_installation_fees',
        'category_materials_fees',
        'category_inspection_fees',
        'delivery_min_working_days',
        'delivery_max_working_days',
        'insurance_fee',
        'vat_percentage',
        'insurance_fee_percentage',
        'installation_flat_addon',
        'installation_materials_cost',
        'installation_schedule_working_days',
        'installation_description',
    ];

    protected $casts = [
        'delivery_fee' => 'integer',
        'category_delivery_fees' => 'array',
        'category_installation_fees' => 'array',
        'category_materials_fees' => 'array',
        'category_inspection_fees' => 'array',
        'delivery_min_working_days' => 'integer',
        'delivery_max_working_days' => 'integer',
        'insurance_fee' => 'integer',
        'vat_percentage' => 'decimal:2',
        'insurance_fee_percentage' => 'decimal:2',
        'installation_flat_addon' => 'integer',
        'installation_materials_cost' => 'integer',
        'installation_schedule_working_days' => 'integer',
    ];

    /**
     * Singleton row for shop checkout (delivery fee, estimates, installation copy).
     */
    public static function get(): self
    {
        $row = self::query()->first();
        if (! $row) {
            $row = self::create([
                'delivery_fee' => (int) config('checkout.delivery_fee', 0),
                'delivery_min_working_days' => (int) config('checkout.delivery_min_working_days', 7),
                'delivery_max_working_days' => (int) config('checkout.delivery_max_working_days', 10),
                'insurance_fee' => (int) config('checkout.insurance_fee', 0),
                'vat_percentage' => (float) config('checkout.vat_percentage', 7.5),
                'insurance_fee_percentage' => (float) config('checkout.insurance_fee_percentage', 3),
                'installation_flat_addon' => (int) config('checkout.installation_flat_addon', 0),
                'installation_materials_cost' => (int) config('checkout.installation_materials_cost', 0),
                'installation_schedule_working_days' => (int) config('checkout.installation_schedule_working_days', 7),
                'installation_description' => (string) config('checkout.installation_text', ''),
            ]);
        }

        return $row;
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function productCategoryDefinitions(): array
    {
        return config('checkout.product_categories', []);
    }

    /**
     * Delivery fee for a Buy Now / BNPL product category (falls back to global delivery_fee).
     */
    public function deliveryFeeForCategory(?string $productCategory): float
    {
        return $this->feeForCategory($productCategory, 'category_delivery_fees', (float) ($this->delivery_fee ?? 0));
    }

    /** Installation fee for TrooSolar installer on product-only category checkouts. */
    public function installationFeeForCategory(?string $productCategory): float
    {
        return $this->feeForCategory($productCategory, 'category_installation_fees', (float) ($this->installation_flat_addon ?? 0));
    }

    /** Materials fee when Own Installer opts into installation materials (product-only). */
    public function materialsFeeForCategory(?string $productCategory): float
    {
        return $this->feeForCategory($productCategory, 'category_materials_fees', (float) ($this->installation_materials_cost ?? 0));
    }

    /** Inspection fee for TrooSolar installer on product-only category checkouts. */
    public function inspectionFeeForCategory(?string $productCategory): float
    {
        return $this->feeForCategory($productCategory, 'category_inspection_fees', 0.0);
    }

    private function feeForCategory(?string $productCategory, string $column, float $globalFallback): float
    {
        $key = trim((string) ($productCategory ?? ''));
        $fees = is_array($this->{$column} ?? null) ? $this->{$column} : [];

        if ($key !== '' && array_key_exists($key, $fees) && $fees[$key] !== null && $fees[$key] !== '') {
            return max(0, (float) $fees[$key]);
        }

        return max(0, $globalFallback);
    }

    /** Normalized map keyed by product category slug. */
    public function normalizedCategoryDeliveryFees(): array
    {
        return $this->normalizedCategoryFeeMap('category_delivery_fees', (int) ($this->delivery_fee ?? 0));
    }

    public function normalizedCategoryInstallationFees(): array
    {
        return $this->normalizedCategoryFeeMap('category_installation_fees', (int) ($this->installation_flat_addon ?? 0));
    }

    public function normalizedCategoryMaterialsFees(): array
    {
        return $this->normalizedCategoryFeeMap('category_materials_fees', (int) ($this->installation_materials_cost ?? 0));
    }

    public function normalizedCategoryInspectionFees(): array
    {
        return $this->normalizedCategoryFeeMap('category_inspection_fees', 0);
    }

    private function normalizedCategoryFeeMap(string $column, int $globalFallback): array
    {
        $defs = self::productCategoryDefinitions();
        $stored = is_array($this->{$column} ?? null) ? $this->{$column} : [];
        $out = [];

        foreach ($defs as $def) {
            $slug = (string) ($def['key'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[$slug] = array_key_exists($slug, $stored)
                ? max(0, (int) $stored[$slug])
                : max(0, $globalFallback);
        }

        return $out;
    }
}
