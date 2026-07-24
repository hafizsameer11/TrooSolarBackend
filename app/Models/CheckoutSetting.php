<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutSetting extends Model
{
    public const CHANNEL_BUY_NOW = 'buy_now';

    public const CHANNEL_SHOP = 'shop';

    protected $table = 'checkout_settings';

    protected $fillable = [
        'channel',
        'delivery_fee',
        'category_delivery_fees',
        'category_installation_fees',
        'category_materials_fees',
        'category_inspection_fees',
        'own_installer_include_inspection',
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
        'own_installer_include_inspection' => 'boolean',
        'delivery_min_working_days' => 'integer',
        'delivery_max_working_days' => 'integer',
        'insurance_fee' => 'integer',
        'vat_percentage' => 'decimal:2',
        'insurance_fee_percentage' => 'decimal:2',
        'installation_flat_addon' => 'integer',
        'installation_materials_cost' => 'integer',
        'installation_schedule_working_days' => 'integer',
    ];

    public static function normalizeChannel(?string $channel): string
    {
        $channel = strtolower(trim((string) $channel));

        return $channel === self::CHANNEL_SHOP
            ? self::CHANNEL_SHOP
            : self::CHANNEL_BUY_NOW;
    }

    /**
     * Settings row for a checkout channel.
     * - buy_now: Buy Now / BNPL product fees
     * - shop: Solar Shop (add-to-cart) checkout fees
     */
    public static function get(?string $channel = self::CHANNEL_BUY_NOW): self
    {
        $channel = self::normalizeChannel($channel);

        $row = self::query()->where('channel', $channel)->first();
        if (! $row && $channel === self::CHANNEL_SHOP) {
            // Bootstrap shop from buy_now if migration has not run / row missing.
            $buyNow = self::query()->where('channel', self::CHANNEL_BUY_NOW)->first()
                ?? self::query()->first();
            if ($buyNow) {
                $attrs = $buyNow->replicate()->getAttributes();
                $attrs['channel'] = self::CHANNEL_SHOP;
                unset($attrs['id']);
                $row = self::create($attrs);
            }
        }

        if (! $row) {
            $row = self::create([
                'channel' => $channel,
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

    /**
     * Sum a fee type across multiple product-only category keys (e.g. battery + inverter).
     *
     * @param  array<int, string>  $categoryKeys
     */
    public function sumProductCategoryFees(array $categoryKeys, string $feeType, ?string $fallbackCategory = null): float
    {
        $keys = array_values(array_unique(array_filter(array_map(
            static fn ($key) => trim((string) $key),
            $categoryKeys
        ))));

        if ($keys === [] && $fallbackCategory) {
            $fallback = trim((string) $fallbackCategory);
            if ($fallback !== '') {
                $keys = [$fallback];
            }
        }

        if ($keys === []) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($keys as $key) {
            $sum += match ($feeType) {
                'delivery' => $this->deliveryFeeForCategory($key),
                'installation' => $this->installationFeeForCategory($key),
                'materials' => $this->materialsFeeForCategory($key),
                'inspection' => $this->inspectionFeeForCategory($key),
                default => 0.0,
            };
        }

        return $sum;
    }

    /**
     * Map a catalog product to a Buy Now product-only fee category slug.
     */
    public static function inferProductFeeCategory(?Product $product): ?string
    {
        if (! $product) {
            return null;
        }

        $title = strtolower(trim((string) ($product->title ?? '')));
        $categoryTitle = strtolower(trim((string) ($product->category->title ?? '')));

        $isBatteryTitle = str_contains($title, 'battery')
            || str_contains($title, 'batteries')
            || str_contains($title, 'lithium')
            || str_contains($title, 'rack');
        $isBatteryCategory = str_contains($categoryTitle, 'battery')
            || str_contains($categoryTitle, 'batteries')
            || str_contains($categoryTitle, 'lithium')
            || str_contains($categoryTitle, 'rack');
        $isInverterTitle = str_contains($title, 'inverter');
        $isInverterCategory = str_contains($categoryTitle, 'inverter');
        $isPanelTitle = str_contains($title, 'panel') || str_contains($title, 'pv');
        $isPanelCategory = str_contains($categoryTitle, 'panel') || str_contains($categoryTitle, 'pv');
        $isKwhTitle = str_contains($title, 'kwh');
        $isAllInOneSystem = str_contains($title, 'all in one')
            || str_contains($title, 'all-in-one')
            || str_contains($title, 'aio')
            || str_contains($title, 'system');
        $isAllInOneCategory = str_contains($categoryTitle, 'all in one')
            || str_contains($categoryTitle, 'all-in-one')
            || str_contains($categoryTitle, 'aio')
            || str_contains($categoryTitle, 'system');

        if (
            ($isPanelTitle || $isPanelCategory)
            && ! $isInverterTitle
            && ! $isBatteryTitle
            && ! $isInverterCategory
            && ! $isBatteryCategory
        ) {
            return 'panels-only';
        }

        if (
            ($isInverterTitle || $isInverterCategory)
            && ! $isBatteryTitle
            && ! $isPanelTitle
            && ! $isBatteryCategory
            && ! $isPanelCategory
        ) {
            return 'inverter-only';
        }

        if (
            ($isBatteryTitle || $isBatteryCategory || $isKwhTitle)
            && ! $isPanelTitle
            && ! $isPanelCategory
            && ! $isAllInOneSystem
            && ! $isAllInOneCategory
            && ! ($isInverterTitle && ! $isKwhTitle)
        ) {
            return 'battery-only';
        }

        return null;
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
