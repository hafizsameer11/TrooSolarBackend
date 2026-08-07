<?php

namespace App\Support;

use App\Models\Category;

/**
 * Solar Shop quantity-based delivery & installation fee tiers.
 *
 * Each tier: { min: int, max: int|null, amount: float }
 * max null (or 0) means "and above".
 */
class ShopQuantityFeeTiers
{
    public const KEY_PANEL_DELIVERY = 'panel_delivery';

    public const KEY_INVERTER_DELIVERY = 'inverter_delivery';

    public const KEY_BATTERY_DELIVERY = 'battery_delivery';

    public const KEY_PANEL_INSTALLATION = 'panel_installation';

    public const KEY_INVERTER_INSTALLATION = 'inverter_installation';

    public const KEY_BATTERY_INSTALLATION = 'battery_installation';

    public const KEY_STREETLIGHT_INSTALLATION = 'streetlight_installation';

    public const CATEGORY_DELIVERY_PREFIX = 'category_';

    public const CATEGORY_DELIVERY_SUFFIX = '_delivery';

    public const CATEGORY_INSTALLATION_SUFFIX = '_installation';

    /** @return array<int, array{min: int, max: int|null, amount: float}> */
    public static function defaultQuantityTiers(): array
    {
        return [
            ['min' => 1, 'max' => 4, 'amount' => 0],
            ['min' => 5, 'max' => 14, 'amount' => 0],
            ['min' => 15, 'max' => 24, 'amount' => 0],
            ['min' => 25, 'max' => null, 'amount' => 0],
        ];
    }

    /** @return array<int, array{min: int, max: int|null, amount: float}> */
    public static function defaultCountTiers(): array
    {
        return [
            ['min' => 1, 'max' => 1, 'amount' => 0],
            ['min' => 2, 'max' => 2, 'amount' => 0],
            ['min' => 3, 'max' => 3, 'amount' => 0],
            ['min' => 4, 'max' => null, 'amount' => 0],
        ];
    }

    /** @return array<string, array<int, array{min: int, max: int|null, amount: float}>> */
    public static function defaultTierMap(): array
    {
        return [
            self::KEY_PANEL_DELIVERY => self::defaultQuantityTiers(),
            self::KEY_INVERTER_DELIVERY => self::defaultQuantityTiers(),
            self::KEY_BATTERY_DELIVERY => self::defaultQuantityTiers(),
            self::KEY_PANEL_INSTALLATION => self::defaultPanelInstallationTiers(),
            self::KEY_INVERTER_INSTALLATION => self::defaultCountTiers(),
            self::KEY_BATTERY_INSTALLATION => self::defaultCountTiers(),
            self::KEY_STREETLIGHT_INSTALLATION => self::defaultCountTiers(),
        ];
    }

    /** @return array<int, array{min: int, max: int|null, amount: float}> */
    public static function defaultPanelInstallationTiers(): array
    {
        $tiers = [];
        for ($start = 1; $start <= 31; $start += 2) {
            $end = $start + 1;
            $tiers[] = ['min' => $start, 'max' => $end, 'amount' => 0.0];
        }
        $tiers[] = ['min' => 33, 'max' => null, 'amount' => 0.0];

        return $tiers;
    }

    public static function categoryDeliveryKey(int $categoryId): string
    {
        return self::CATEGORY_DELIVERY_PREFIX.$categoryId.self::CATEGORY_DELIVERY_SUFFIX;
    }

    public static function categoryInstallationKey(int $categoryId): string
    {
        return self::CATEGORY_DELIVERY_PREFIX.$categoryId.self::CATEGORY_INSTALLATION_SUFFIX;
    }

    public static function isCategoryTierKey(string $key): bool
    {
        return str_starts_with($key, self::CATEGORY_DELIVERY_PREFIX)
            && (str_ends_with($key, self::CATEGORY_DELIVERY_SUFFIX)
                || str_ends_with($key, self::CATEGORY_INSTALLATION_SUFFIX));
    }

    public static function categoryIdFromTierKey(string $key): ?int
    {
        if (! self::isCategoryTierKey($key)) {
            return null;
        }

        $body = substr($key, strlen(self::CATEGORY_DELIVERY_PREFIX));
        $body = str_replace([self::CATEGORY_DELIVERY_SUFFIX, self::CATEGORY_INSTALLATION_SUFFIX], '', $body);

        return ctype_digit($body) ? (int) $body : null;
    }

    public static function isManagedCoreCategoryTitle(string $title): bool
    {
        $name = strtolower(trim($title));
        if ($name === '') {
            return false;
        }

        return str_contains($name, 'panel')
            || str_contains($name, 'inverter')
            || str_contains($name, 'battery')
            || str_contains($name, 'batteries')
            || str_contains($name, 'lithium')
            || str_contains($name, 'streetlight')
            || str_contains($name, 'street light');
    }

    /**
     * @return array<int, array{key: string, title: string, description: string, kind: string}>
     */
    public static function tierSectionDefinitions(): array
    {
        $sections = [
            [
                'key' => self::KEY_PANEL_DELIVERY,
                'title' => 'Solar panel delivery fees',
                'description' => 'By total panel count (standalone panels, solar bundles, streetlight panel count).',
                'kind' => 'delivery',
            ],
            [
                'key' => self::KEY_INVERTER_DELIVERY,
                'title' => 'Inverter delivery fees',
                'description' => 'By total inverter count (products and inverter / solar bundles).',
                'kind' => 'delivery',
            ],
            [
                'key' => self::KEY_BATTERY_DELIVERY,
                'title' => 'Battery delivery fees',
                'description' => 'By total battery count (products and bundles).',
                'kind' => 'delivery',
            ],
            [
                'key' => self::KEY_PANEL_INSTALLATION,
                'title' => 'Solar panel installation fees',
                'description' => 'By panel count in cart (panels and solar bundles — not streetlights).',
                'kind' => 'installation',
            ],
            [
                'key' => self::KEY_STREETLIGHT_INSTALLATION,
                'title' => 'Solar streetlight installation fees',
                'description' => 'By streetlight unit count. Delivery still uses solar panel delivery tiers above.',
                'kind' => 'installation',
            ],
            [
                'key' => self::KEY_INVERTER_INSTALLATION,
                'title' => 'Inverter installation fees',
                'description' => 'By inverter count. Added with panel, streetlight, and battery installation.',
                'kind' => 'installation',
            ],
            [
                'key' => self::KEY_BATTERY_INSTALLATION,
                'title' => 'Battery installation fees',
                'description' => 'By battery count. 4 batteries + 1 inverter bills as 3 batteries.',
                'kind' => 'installation',
            ],
        ];

        $categories = Category::query()
            ->orderByRaw('COALESCE(sort_order, id) asc')
            ->get(['id', 'title']);

        foreach ($categories as $category) {
            if (self::isManagedCoreCategoryTitle((string) ($category->title ?? ''))) {
                continue;
            }

            $id = (int) $category->id;
            $label = (string) ($category->title ?: ('Category #'.$id));

            $sections[] = [
                'key' => self::categoryDeliveryKey($id),
                'title' => $label.' — delivery fees',
                'description' => 'Quantity tiers for products in the “'.$label.'” store heading.',
                'kind' => 'delivery',
                'category_id' => $id,
            ];
            $sections[] = [
                'key' => self::categoryInstallationKey($id),
                'title' => $label.' — installation fees',
                'description' => 'Quantity tiers for installation on “'.$label.'” products.',
                'kind' => 'installation',
                'category_id' => $id,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, array<int, array{min: int, max: int|null, amount: float}>>
     */
    public static function normalizeMap(?array $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $out = [];

        foreach (self::defaultTierMap() as $key => $defaultTiers) {
            $out[$key] = self::normalizeTierList(
                is_array($stored[$key] ?? null) ? $stored[$key] : $defaultTiers
            );
        }

        foreach (self::tierSectionDefinitions() as $section) {
            $key = (string) ($section['key'] ?? '');
            if ($key === '' || isset($out[$key])) {
                continue;
            }

            $default = str_ends_with($key, self::CATEGORY_INSTALLATION_SUFFIX)
                ? self::defaultCountTiers()
                : self::defaultQuantityTiers();

            $out[$key] = self::normalizeTierList(
                is_array($stored[$key] ?? null) ? $stored[$key] : $default
            );
        }

        foreach ($stored as $key => $tiers) {
            if (! is_string($key) || ! self::isCategoryTierKey($key) || isset($out[$key])) {
                continue;
            }
            if (! is_array($tiers)) {
                continue;
            }
            $out[$key] = self::normalizeTierList($tiers);
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $tiers
     * @return array<int, array{min: int, max: int|null, amount: float}>
     */
    public static function normalizeTierList(array $tiers): array
    {
        $normalized = [];

        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $min = max(0, (int) ($tier['min'] ?? 0));
            $maxRaw = $tier['max'] ?? null;
            $max = ($maxRaw === null || $maxRaw === '' || (int) $maxRaw <= 0)
                ? null
                : max($min, (int) $maxRaw);
            $amount = max(0, (float) ($tier['amount'] ?? 0));

            if ($min <= 0) {
                $min = 1;
            }

            $normalized[] = [
                'min' => $min,
                'max' => $max,
                'amount' => round($amount, 2),
            ];
        }

        usort($normalized, static function ($a, $b) {
            return $a['min'] <=> $b['min'];
        });

        return $normalized;
    }

    /**
     * @param  array<int, array{min: int, max: int|null, amount: float}>  $tiers
     */
    public static function feeForQuantity(array $tiers, int $quantity): float
    {
        if ($quantity <= 0 || $tiers === []) {
            return 0.0;
        }

        $matches = array_values(array_filter(
            $tiers,
            static function (array $tier) use ($quantity) {
                $min = (int) ($tier['min'] ?? 0);
                $max = $tier['max'] ?? null;

                if ($quantity < $min) {
                    return false;
                }

                return $max === null || $quantity <= $max;
            }
        ));

        if ($matches === []) {
            return 0.0;
        }

        usort($matches, static function ($a, $b) {
            $minCmp = ($b['min'] ?? 0) <=> ($a['min'] ?? 0);
            if ($minCmp !== 0) {
                return $minCmp;
            }

            $aOpen = ($a['max'] ?? null) === null;
            $bOpen = ($b['max'] ?? null) === null;
            if ($aOpen !== $bOpen) {
                return $aOpen ? 1 : -1;
            }

            return ($a['max'] ?? PHP_INT_MAX) <=> ($b['max'] ?? PHP_INT_MAX);
        });

        return round((float) ($matches[0]['amount'] ?? 0), 2);
    }

    /**
     * @param  array<string, array<int, array{min: int, max: int|null, amount: float}>>  $map
     * @return array<string, array<int, array{min: int, max: int|null, amount: float}>>
     */
    public static function sanitizePayload(array $map): array
    {
        $allowedKeys = array_map(
            static fn (array $section) => (string) ($section['key'] ?? ''),
            self::tierSectionDefinitions()
        );

        $out = [];
        foreach ($allowedKeys as $key) {
            if ($key === '' || ! isset($map[$key]) || ! is_array($map[$key])) {
                continue;
            }
            $out[$key] = self::normalizeTierList($map[$key]);
        }

        foreach ($map as $key => $tiers) {
            if (! is_string($key) || ! self::isCategoryTierKey($key) || isset($out[$key])) {
                continue;
            }
            if (! is_array($tiers)) {
                continue;
            }
            $out[$key] = self::normalizeTierList($tiers);
        }

        return $out;
    }

    /**
     * Ensure a new catalog category has tier rows in shop checkout settings.
     */
    public static function bootstrapCategoryTiers(int $categoryId, ?string $categoryTitle = null): void
    {
        if ($categoryTitle !== null && self::isManagedCoreCategoryTitle($categoryTitle)) {
            return;
        }

        $settings = \App\Models\CheckoutSetting::get(\App\Models\CheckoutSetting::CHANNEL_SHOP);
        $stored = is_array($settings->shop_quantity_fee_tiers ?? null)
            ? $settings->shop_quantity_fee_tiers
            : [];

        $deliveryKey = self::categoryDeliveryKey($categoryId);
        $installationKey = self::categoryInstallationKey($categoryId);
        $changed = false;

        if (! isset($stored[$deliveryKey])) {
            $stored[$deliveryKey] = self::defaultQuantityTiers();
            $changed = true;
        }
        if (! isset($stored[$installationKey])) {
            $stored[$installationKey] = self::defaultCountTiers();
            $changed = true;
        }

        if ($changed) {
            $settings->shop_quantity_fee_tiers = self::sanitizePayload($stored);
            $settings->save();
        }
    }
}
