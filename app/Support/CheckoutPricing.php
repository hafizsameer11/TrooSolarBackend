<?php

namespace App\Support;

use App\Models\Bundles;
use App\Models\Category;
use App\Models\CheckoutSetting;
use App\Models\DeliveryLocation;
use App\Models\Product;
use App\Models\State;
use App\Support\ShopQuantityFeeTiers;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CheckoutPricing
{
    /**
     * Add N weekdays (Mon–Fri) starting from the given instant (day granularity).
     */
    public static function addWorkingDays(Carbon $from, int $workingDays): Carbon
    {
        if ($workingDays <= 0) {
            return $from->copy();
        }
        $d = $from->copy()->startOfDay();
        $added = 0;
        while ($added < $workingDays) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $added++;
            }
        }

        return $d;
    }

    public static function installationTotalFromCartItems(Collection $cartItems): int
    {
        $sum = $cartItems->sum(function ($item) {
            if (! $item->itemable) {
                return 0;
            }
            $qty = max(1, (int) ($item->quantity ?? 1));
            $perUnit = (float) (
                $item->itemable->installation_price
                ?? $item->itemable->install_price
                ?? $item->itemable->installation_cost
                ?? 0
            );

            return max(0, $perUnit) * $qty;
        });

        return (int) round($sum);
    }

    public static function deliveryWindow(CheckoutSetting $settings): array
    {
        $base = Carbon::now()->startOfDay();
        $min = max(1, (int) $settings->delivery_min_working_days);
        $max = max($min, (int) $settings->delivery_max_working_days);
        $from = self::addWorkingDays($base, $min);
        $to = self::addWorkingDays($base, $max);
        $label = "{$min}–{$max} working days";

        return [
            'estimated_from' => $from->toDateString(),
            'estimated_to' => $to->toDateString(),
            'label' => $label,
            'min_working_days' => $min,
            'max_working_days' => $max,
        ];
    }

    public static function installationEstimatedDate(CheckoutSetting $settings): string
    {
        $days = max(1, (int) $settings->installation_schedule_working_days);
        $base = Carbon::now()->startOfDay();

        return self::addWorkingDays($base, $days)->toDateString();
    }

    /**
     * Insurance as % of items subtotal only (installation / inspection excluded).
     * $installationFull is retained for call-site compatibility and ignored.
     */
    public static function insuranceAmountFromPercent(float $itemsSubtotal, float $installationFull, float $percent): float
    {
        if ($percent <= 0) {
            return 0.0;
        }

        return round(max(0, $itemsSubtotal) * ($percent / 100.0), 2);
    }

    /**
     * Sum admin category inspection fees for distinct product/bundle categories.
     */
    public static function inspectionTotalFromCartItems(Collection $cartItems, CheckoutSetting $settings): int
    {
        return (int) round(self::shopCartCategoryFees($cartItems, $settings)['inspection']);
    }

    /**
     * Collect unique Solar Shop fee keys from cart products and bundles.
     * Products use category_id; bundles map bundle_type to their catalog category.
     * (Buy Now keeps inferProductFeeCategory separately — do not use it here.)
     *
     * @return array<int, string>
     */
    public static function shopCartFeeCategoryKeys(Collection $cartItems): array
    {
        $keys = [];
        $bundleCategoryIds = null;

        foreach ($cartItems as $item) {
            $model = $item->itemable ?? null;

            if ($model instanceof \App\Models\Product) {
                $categoryId = (int) ($model->category_id ?? 0);
                if ($categoryId <= 0) {
                    $model->loadMissing('category');
                    $categoryId = (int) ($model->category?->id ?? 0);
                }
                if ($categoryId > 0) {
                    $keys[] = (string) $categoryId;
                }
                continue;
            }

            if (! $model instanceof Bundles) {
                continue;
            }

            // Bundles do not have category_id. Resolve the same virtual catalog
            // categories used by the storefront: Solar Bundles / Inverter Bundles.
            $bundleCategoryIds ??= Category::query()
                ->get(['id', 'title'])
                ->mapWithKeys(static function ($category) {
                    $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $category->title));

                    return [$key => (int) $category->id];
                })
                ->all();

            $bundleType = strtolower(preg_replace(
                '/[^a-z0-9]+/i',
                '',
                (string) ($model->bundle_type ?? '')
            ));
            $hasInverter = str_contains($bundleType, 'inverter');
            $hasBattery = str_contains($bundleType, 'battery')
                || str_contains($bundleType, 'batteries');
            $categoryId = 0;

            if (str_contains($bundleType, 'solar') && $hasInverter && $hasBattery) {
                $categoryId = (int) ($bundleCategoryIds['solarbundles'] ?? 0);
            } elseif ($hasInverter && $hasBattery) {
                $categoryId = (int) ($bundleCategoryIds['inverterbundles'] ?? 0);
            }

            if ($categoryId > 0) {
                $keys[] = (string) $categoryId;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Solar Shop cart fees.
     *
     * Delivery uses product-type rules (panel tiers, inverter waiver, etc.).
     * Inspection is one flat fee for the whole cart (not per category).
     * Installation / materials still sum per distinct category.
     *
     * @return array{
     *   category_keys: array<int, string>,
     *   delivery: float,
     *   installation: float,
     *   inspection: float,
     *   materials: float
     * }
     */
    public static function shopCartCategoryFees(Collection $cartItems, CheckoutSetting $settings): array
    {
        $keys = self::shopCartFeeCategoryKeys($cartItems);

        $delivery = self::shopCartDeliveryFee($cartItems, $settings);

        $installation = self::shopCartInstallationFee($cartItems, $settings);
        $inspection = self::shopFlatInspectionFee($settings);
        $materials = $keys !== []
            ? (float) $settings->sumProductCategoryFees($keys, 'materials')
            : 0.0;

        return [
            'category_keys' => $keys,
            'delivery' => round($delivery, 2),
            'installation' => round($installation, 2),
            'inspection' => round($inspection, 2),
            'materials' => round($materials, 2),
            'breakdown' => self::shopCartFeeBreakdown($cartItems, $settings),
        ];
    }

    /**
     * Detailed fee breakdown for checkout UI (quantities, tier labels, amounts).
     *
     * @return array{
     *   quantities: array<string, int>,
     *   delivery_lines: array<int, array{label: string, quantity: int, amount: float}>,
     *   installation_lines: array<int, array{label: string, quantity: int, amount: float}>,
     *   delivery_total: float,
     *   installation_total: float
     * }
     */
    public static function shopCartFeeBreakdown(Collection $cartItems, CheckoutSetting $settings): array
    {
        $analysis = self::analyzeShopCartContents($cartItems);
        $tierMap = $settings->normalizedShopQuantityFeeTiers();

        $inverterQty = (int) $analysis['inverter_qty'];
        $batteryQty = (int) $analysis['battery_qty'];
        $inverterInstallQty = $inverterQty;
        $batteryInstallQty = $batteryQty;
        if ($inverterQty >= 4 && $batteryQty === 1) {
            $inverterInstallQty = 3;
        }
        if ($batteryQty >= 4 && $inverterQty === 1) {
            $batteryInstallQty = 3;
        }

        $deliveryLines = [];
        $installationLines = [];

        $addLine = static function (array &$lines, string $label, int $qty, float $amount): void {
            if ($qty <= 0) {
                return;
            }
            $lines[] = ['label' => $label, 'quantity' => $qty, 'amount' => round($amount, 2)];
        };

        if ($analysis['panel_delivery_qty'] > 0) {
            $amount = ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_PANEL_DELIVERY] ?? [],
                $analysis['panel_delivery_qty']
            );
            $addLine($deliveryLines, 'Solar panel delivery', $analysis['panel_delivery_qty'], $amount);
        }

        if ($analysis['inverter_qty'] > 0) {
            $amount = ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_INVERTER_DELIVERY] ?? [],
                $analysis['inverter_qty']
            );
            $addLine($deliveryLines, 'Inverter delivery', $analysis['inverter_qty'], $amount);
        }

        if ($analysis['battery_qty'] > 0) {
            $amount = ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_BATTERY_DELIVERY] ?? [],
                $analysis['battery_qty']
            );
            $addLine($deliveryLines, 'Battery delivery', $analysis['battery_qty'], $amount);
        }

        foreach ($analysis['category_quantities'] as $categoryId => $categoryQty) {
            $key = ShopQuantityFeeTiers::categoryDeliveryKey((int) $categoryId);
            $amount = ShopQuantityFeeTiers::feeForQuantity($tierMap[$key] ?? [], (int) $categoryQty);
            $label = trim((string) ($analysis['category_labels'][$categoryId] ?? 'Category #'.$categoryId));
            $addLine($deliveryLines, $label.' delivery', (int) $categoryQty, $amount);
        }

        if ($analysis['panel_install_qty'] > 0) {
            $amount = ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_PANEL_INSTALLATION] ?? [],
                $analysis['panel_install_qty']
            );
            $addLine($installationLines, 'Solar panel installation', $analysis['panel_install_qty'], $amount);
        }

        if ($analysis['streetlight_qty'] > 0) {
            $amount = ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_STREETLIGHT_INSTALLATION] ?? [],
                $analysis['streetlight_qty']
            );
            $addLine($installationLines, 'Solar streetlight installation', $analysis['streetlight_qty'], $amount);
        }

        if ($inverterInstallQty > 0) {
            $amount = ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_INVERTER_INSTALLATION] ?? [],
                $inverterInstallQty
            );
            $label = $inverterInstallQty !== $inverterQty
                ? 'Inverter installation (adjusted)'
                : 'Inverter installation';
            $addLine($installationLines, $label, $inverterInstallQty, $amount);
        }

        if ($batteryInstallQty > 0) {
            $amount = ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_BATTERY_INSTALLATION] ?? [],
                $batteryInstallQty
            );
            $label = $batteryInstallQty !== $batteryQty
                ? 'Battery installation (adjusted)'
                : 'Battery installation';
            $addLine($installationLines, $label, $batteryInstallQty, $amount);
        }

        foreach ($analysis['category_quantities'] as $categoryId => $categoryQty) {
            $key = ShopQuantityFeeTiers::categoryInstallationKey((int) $categoryId);
            $amount = ShopQuantityFeeTiers::feeForQuantity($tierMap[$key] ?? [], (int) $categoryQty);
            $label = trim((string) ($analysis['category_labels'][$categoryId] ?? 'Category #'.$categoryId));
            $addLine($installationLines, $label.' installation', (int) $categoryQty, $amount);
        }

        $deliveryTotal = round(array_sum(array_column($deliveryLines, 'amount')), 2);
        $installationTotal = round(array_sum(array_column($installationLines, 'amount')), 2);

        return [
            'quantities' => [
                'panels_delivery' => (int) $analysis['panel_delivery_qty'],
                'panels_installation' => (int) $analysis['panel_install_qty'],
                'inverters' => (int) $analysis['inverter_qty'],
                'batteries' => (int) $analysis['battery_qty'],
                'streetlights' => (int) $analysis['streetlight_qty'],
            ],
            'delivery_lines' => $deliveryLines,
            'installation_lines' => $installationLines,
            'delivery_total' => $deliveryTotal,
            'installation_total' => $installationTotal,
        ];
    }

    /**
     * One flat inspection fee for Solar Shop checkout (not summed per product category).
     */
    public static function shopFlatInspectionFee(CheckoutSetting $settings): float
    {
        $fees = $settings->normalizedCategoryInspectionFees(CheckoutSetting::CHANNEL_SHOP);
        $positive = array_filter(
            array_map(static fn ($v) => (float) $v, $fees),
            static fn ($v) => $v > 0
        );

        if ($positive === []) {
            return 0.0;
        }

        // Admin may store the flat fee on any category row; use the configured value.
        return round(max($positive), 2);
    }

    /**
     * Solar Shop delivery from admin quantity tiers (panels, inverters, batteries)
     * plus per-category delivery for other catalog items.
     */
    public static function shopCartDeliveryFee(Collection $cartItems, CheckoutSetting $settings): float
    {
        $analysis = self::analyzeShopCartContents($cartItems);
        $tierMap = $settings->normalizedShopQuantityFeeTiers();
        $delivery = 0.0;

        if ($analysis['panel_delivery_qty'] > 0) {
            $delivery += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_PANEL_DELIVERY] ?? [],
                $analysis['panel_delivery_qty']
            );
        }

        if ($analysis['inverter_qty'] > 0) {
            $delivery += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_INVERTER_DELIVERY] ?? [],
                $analysis['inverter_qty']
            );
        }

        if ($analysis['battery_qty'] > 0) {
            $delivery += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_BATTERY_DELIVERY] ?? [],
                $analysis['battery_qty']
            );
        }

        foreach ($analysis['category_quantities'] as $categoryId => $categoryQty) {
            $key = ShopQuantityFeeTiers::categoryDeliveryKey((int) $categoryId);
            $delivery += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[$key] ?? [],
                (int) $categoryQty
            );
        }

        return round($delivery, 2);
    }

    /**
     * Solar Shop installation: sum panel + inverter + battery tier fees.
     * 4 inverters + 1 battery (or vice versa) bills installation at 3 of the dominant type.
     */
    public static function shopCartInstallationFee(Collection $cartItems, CheckoutSetting $settings): float
    {
        $analysis = self::analyzeShopCartContents($cartItems);
        $tierMap = $settings->normalizedShopQuantityFeeTiers();
        $total = 0.0;

        $inverterQty = (int) $analysis['inverter_qty'];
        $batteryQty = (int) $analysis['battery_qty'];
        $inverterInstallQty = $inverterQty;
        $batteryInstallQty = $batteryQty;

        if ($inverterQty >= 4 && $batteryQty === 1) {
            $inverterInstallQty = 3;
        }
        if ($batteryQty >= 4 && $inverterQty === 1) {
            $batteryInstallQty = 3;
        }

        if ($analysis['panel_install_qty'] > 0) {
            $total += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_PANEL_INSTALLATION] ?? [],
                $analysis['panel_install_qty']
            );
        }

        if ($analysis['streetlight_qty'] > 0) {
            $total += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_STREETLIGHT_INSTALLATION] ?? [],
                $analysis['streetlight_qty']
            );
        }

        if ($inverterInstallQty > 0) {
            $total += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_INVERTER_INSTALLATION] ?? [],
                $inverterInstallQty
            );
        }

        if ($batteryInstallQty > 0) {
            $total += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[ShopQuantityFeeTiers::KEY_BATTERY_INSTALLATION] ?? [],
                $batteryInstallQty
            );
        }

        foreach ($analysis['category_quantities'] as $categoryId => $categoryQty) {
            $key = ShopQuantityFeeTiers::categoryInstallationKey((int) $categoryId);
            $total += ShopQuantityFeeTiers::feeForQuantity(
                $tierMap[$key] ?? [],
                (int) $categoryQty
            );
        }

        return round($total, 2);
    }

    /**
     * @return array{
     *   panel_delivery_qty: int,
     *   panel_install_qty: int,
     *   battery_qty: int,
     *   inverter_qty: int,
     *   streetlight_qty: int,
     *   has_battery: bool,
     *   has_inverter: bool,
     *   has_streetlight: bool,
     *   has_all_in_one: bool,
     *   panel_category_id: ?int,
     *   battery_category_id: ?int,
     *   inverter_category_id: ?int,
     *   streetlight_category_id: ?int,
     *   all_in_one_category_id: ?int,
     *   category_quantities: array<int, int>,
     *   category_labels: array<int, string>
     * }
     */
    public static function analyzeShopCartContents(Collection $cartItems): array
    {
        $result = [
            'panel_delivery_qty' => 0,
            'panel_install_qty' => 0,
            'battery_qty' => 0,
            'inverter_qty' => 0,
            'streetlight_qty' => 0,
            'has_battery' => false,
            'has_inverter' => false,
            'has_streetlight' => false,
            'has_all_in_one' => false,
            'panel_category_id' => null,
            'battery_category_id' => null,
            'inverter_category_id' => null,
            'streetlight_category_id' => null,
            'all_in_one_category_id' => null,
            'category_quantities' => [],
            'category_labels' => [],
        ];

        foreach ($cartItems as $item) {
            $qty = max(1, (int) ($item->quantity ?? 1));
            $model = $item->itemable ?? null;

            if ($model instanceof Product) {
                $model->loadMissing('category');
                $categoryId = (int) ($model->category_id ?? $model->category?->id ?? 0);
                $categoryTitle = strtolower(trim((string) ($model->category?->title ?? '')));
                $productTitle = strtolower(trim((string) ($model->title ?? '')));

                if (self::isShopSolarPanelItem($categoryTitle, $productTitle)) {
                    $result['panel_delivery_qty'] += $qty;
                    $result['panel_install_qty'] += $qty;
                    $result['panel_category_id'] ??= $categoryId > 0 ? $categoryId : null;
                    continue;
                }

                if (self::isShopBatteryItem($categoryTitle, $productTitle)) {
                    $result['battery_qty'] += $qty;
                    $result['has_battery'] = true;
                    $result['battery_category_id'] ??= $categoryId > 0 ? $categoryId : null;
                    continue;
                }

                if (self::isShopStreetlightItem($categoryTitle, $productTitle)) {
                    $result['has_streetlight'] = true;
                    $result['streetlight_category_id'] ??= $categoryId > 0 ? $categoryId : null;
                    $result['streetlight_qty'] += $qty;
                    $result['panel_delivery_qty'] += self::streetlightPanelQuantity($productTitle, $qty);
                    continue;
                }

                if (self::isShopAllInOneItem($categoryTitle, $productTitle)) {
                    $result['has_all_in_one'] = true;
                    $result['all_in_one_category_id'] ??= $categoryId > 0 ? $categoryId : null;
                }

                if (self::isShopInverterItem($categoryTitle, $productTitle)) {
                    $result['inverter_qty'] += $qty;
                    $result['has_inverter'] = true;
                    $result['inverter_category_id'] ??= $categoryId > 0 ? $categoryId : null;
                    continue;
                }

                if ($categoryId > 0 && ! ShopQuantityFeeTiers::isManagedCoreCategoryTitle($categoryTitle)) {
                    $result['category_quantities'][$categoryId] = ($result['category_quantities'][$categoryId] ?? 0) + $qty;
                    $result['category_labels'][$categoryId] = (string) ($model->category?->title ?? ('Category #'.$categoryId));
                }

                continue;
            }

            if (! $model instanceof Bundles) {
                continue;
            }

            $bundleType = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($model->bundle_type ?? '')));
            $bundleTitle = strtolower(trim((string) ($model->title ?? '')));
            $hasSolar = str_contains($bundleType, 'solar') || str_contains($bundleType, 'panel');
            $hasInverter = str_contains($bundleType, 'inverter');
            $hasBattery = str_contains($bundleType, 'battery') || str_contains($bundleType, 'batteries');

            if ($hasSolar) {
                $panelCount = self::bundleMaterialQuantity($model, $qty, 'panel');
                $result['panel_delivery_qty'] += $panelCount;
                $result['panel_install_qty'] += $panelCount;
            }

            if ($hasBattery) {
                $result['battery_qty'] += self::bundleMaterialQuantity($model, $qty, 'battery');
                $result['has_battery'] = true;
            }

            if (self::isShopStreetlightItem('', $bundleTitle)) {
                $result['has_streetlight'] = true;
                $result['streetlight_qty'] += $qty;
                $result['panel_delivery_qty'] += self::streetlightPanelQuantity($bundleTitle, $qty);
            }

            if ($hasInverter) {
                $result['inverter_qty'] += self::bundleMaterialQuantity($model, $qty, 'inverter');
                $result['has_inverter'] = true;
            }
        }

        return $result;
    }

    private static function shopCategoryDeliveryFee(CheckoutSetting $settings, ?int $categoryId): float
    {
        if (! $categoryId || $categoryId <= 0) {
            return 0.0;
        }

        return (float) $settings->deliveryFeeForCategory((string) $categoryId);
    }

    private static function findShopCategoryIdByTitlePatterns(array $patterns): ?int
    {
        $categories = Category::query()->get(['id', 'title']);

        foreach ($patterns as $pattern) {
            $needle = strtolower(trim($pattern));
            if ($needle === '') {
                continue;
            }

            foreach ($categories as $category) {
                $title = strtolower(trim((string) ($category->title ?? '')));
                if ($title !== '' && str_contains($title, $needle)) {
                    return (int) $category->id;
                }
            }
        }

        return null;
    }

    private static function bundleMaterialQuantity(Bundles $bundle, int $cartQty, string $kind): int
    {
        $bundle->loadMissing('bundleMaterials.material');
        $perBundle = 0;

        foreach ($bundle->bundleMaterials as $bundleMaterial) {
            $name = strtolower((string) ($bundleMaterial->material->name ?? ''));
            $materialQty = max(1, (int) ($bundleMaterial->quantity ?? 1));

            if ($kind === 'panel') {
                if (
                    str_contains($name, 'solar panel')
                    || (str_contains($name, 'panel') && str_contains($name, 'solar'))
                ) {
                    $perBundle += $materialQty;
                }
            } elseif ($kind === 'inverter' && str_contains($name, 'inverter')) {
                $perBundle += $materialQty;
            } elseif ($kind === 'battery' && (str_contains($name, 'battery') || str_contains($name, 'kwh'))) {
                $perBundle += $materialQty;
            }
        }

        if ($perBundle <= 0) {
            $bundleType = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($bundle->bundle_type ?? '')));
            if ($kind === 'panel' && (str_contains($bundleType, 'solar') || str_contains($bundleType, 'panel'))) {
                $perBundle = 1;
            } elseif ($kind === 'inverter' && str_contains($bundleType, 'inverter')) {
                $perBundle = 1;
            } elseif ($kind === 'battery' && (str_contains($bundleType, 'battery') || str_contains($bundleType, 'batteries'))) {
                $perBundle = 1;
            }
        }

        return $perBundle * max(1, $cartQty);
    }

    private static function streetlightPanelQuantity(string $title, int $cartQty): int
    {
        $normalized = strtolower(trim($title));
        if (preg_match('/(\d+)\s*(?:x\s*)?panel/i', $normalized, $matches)) {
            return max(1, (int) $matches[1]) * max(1, $cartQty);
        }

        return max(1, $cartQty);
    }

    private static function bundleSolarPanelQuantity(Bundles $bundle, int $cartQty): int
    {
        return self::bundleMaterialQuantity($bundle, $cartQty, 'panel');
    }

    private static function isShopSolarPanelItem(string $categoryTitle, string $productTitle): bool
    {
        $isPanelCategory = str_contains($categoryTitle, 'panel') || str_contains($categoryTitle, 'pv');
        $isPanelTitle = str_contains($productTitle, 'panel') || str_contains($productTitle, 'pv');
        $isInverter = str_contains($categoryTitle, 'inverter') || str_contains($productTitle, 'inverter');
        $isBattery = str_contains($categoryTitle, 'battery')
            || str_contains($categoryTitle, 'batteries')
            || str_contains($categoryTitle, 'lithium')
            || str_contains($productTitle, 'battery')
            || str_contains($productTitle, 'batteries');

        return ($isPanelCategory || $isPanelTitle) && ! $isInverter && ! $isBattery;
    }

    private static function isShopBatteryItem(string $categoryTitle, string $productTitle): bool
    {
        if (self::isShopAllInOneItem($categoryTitle, $productTitle)) {
            return false;
        }

        $isBatteryCategory = str_contains($categoryTitle, 'battery')
            || str_contains($categoryTitle, 'batteries')
            || str_contains($categoryTitle, 'lithium')
            || str_contains($categoryTitle, 'rack');
        $isBatteryTitle = str_contains($productTitle, 'battery')
            || str_contains($productTitle, 'batteries')
            || str_contains($productTitle, 'lithium')
            || str_contains($productTitle, 'rack');
        $isKwhTitle = str_contains($productTitle, 'kwh');
        $isPanel = self::isShopSolarPanelItem($categoryTitle, $productTitle);

        return ($isBatteryCategory || $isBatteryTitle || $isKwhTitle) && ! $isPanel;
    }

    private static function isShopInverterItem(string $categoryTitle, string $productTitle): bool
    {
        $isInverterCategory = str_contains($categoryTitle, 'inverter');
        $isInverterTitle = str_contains($productTitle, 'inverter');
        $isPanel = self::isShopSolarPanelItem($categoryTitle, $productTitle);
        $isBattery = self::isShopBatteryItem($categoryTitle, $productTitle);

        return ($isInverterCategory || $isInverterTitle) && ! $isPanel && ! $isBattery;
    }

    private static function isShopStreetlightItem(string $categoryTitle, string $productTitle): bool
    {
        return str_contains($categoryTitle, 'streetlight')
            || str_contains($categoryTitle, 'street light')
            || str_contains($categoryTitle, 'street-light')
            || str_contains($productTitle, 'streetlight')
            || str_contains($productTitle, 'street light')
            || str_contains($productTitle, 'street-light');
    }

    private static function isShopAllInOneItem(string $categoryTitle, string $productTitle): bool
    {
        return str_contains($categoryTitle, 'all in one')
            || str_contains($categoryTitle, 'all-in-one')
            || str_contains($categoryTitle, 'aio')
            || str_contains($productTitle, 'all in one')
            || str_contains($productTitle, 'all-in-one')
            || str_contains($productTitle, 'aio')
            || (
                str_contains($productTitle, 'kva')
                && str_contains($productTitle, 'kwh')
                && ! str_contains($productTitle, 'inverter')
            );
    }

    public static function vatAmount(float $taxableBase, float $vatPercent): float
    {
        if ($vatPercent <= 0 || $taxableBase <= 0) {
            return 0.0;
        }

        return round($taxableBase * ($vatPercent / 100.0), 2);
    }

    /**
     * Resolve Buy Now / BNPL checkout delivery & installation fees.
     * No hardcoded ₦25k/₦50k — bundle materials, then location/state (non-legacy), then admin checkout settings.
     *
     * @return array{delivery_fee: float, installation_fee: float, inspection_fee_from_bundle: float}
     */
    public static function resolveBuyNowCheckoutFees(
        ?Bundles $bundle,
        ?int $deliveryLocationId,
        ?int $stateId,
        ?CheckoutSetting $settings = null,
        ?string $productCategory = null,
    ): array {
        $settings ??= CheckoutSetting::get(CheckoutSetting::CHANNEL_BUY_NOW);

        $deliveryFee = 0.0;
        $installationFee = 0.0;
        $inspectionFromBundle = 0.0;
        $deliveryFromBundle = false;
        $installationFromBundle = false;

        if ($bundle) {
            $bundle->loadMissing('bundleMaterials.material');
            foreach ($bundle->bundleMaterials as $bm) {
                $materialName = (string) ($bm->material->name ?? '');
                $rate = (float) ($bm->material->selling_rate ?? $bm->material->rate ?? 0);
                if ($rate <= 0) {
                    continue;
                }
                if (str_contains($materialName, 'Installation Fees')) {
                    $installationFee = $rate;
                    $installationFromBundle = true;
                } elseif (str_contains($materialName, 'Delivery Fees')) {
                    $deliveryFee = $rate;
                    $deliveryFromBundle = true;
                } elseif (str_contains($materialName, 'Inspection Fees')) {
                    $inspectionFromBundle = $rate;
                }
            }
        }

        if (! $deliveryFromBundle || ! $installationFromBundle) {
            if ($deliveryLocationId) {
                $location = DeliveryLocation::find($deliveryLocationId);
                if ($location) {
                    if (! $deliveryFromBundle) {
                        $deliveryFee = LegacyInvoiceFees::effectiveAmount(
                            (float) ($location->delivery_fee ?? 0),
                            'delivery'
                        );
                    }
                    if (! $installationFromBundle) {
                        $installationFee = LegacyInvoiceFees::effectiveAmount(
                            (float) ($location->installation_fee ?? 0),
                            'installation'
                        );
                    }
                }
            } elseif ($stateId) {
                $state = State::find($stateId);
                if ($state) {
                    if (! $deliveryFromBundle) {
                        $deliveryFee = LegacyInvoiceFees::effectiveAmount(
                            (float) ($state->default_delivery_fee ?? 0),
                            'delivery'
                        );
                    }
                    if (! $installationFromBundle) {
                        $installationFee = LegacyInvoiceFees::effectiveAmount(
                            (float) ($state->default_installation_fee ?? 0),
                            'installation'
                        );
                    }
                }
            }
        }

        if (! $deliveryFromBundle && $deliveryFee <= 0) {
            $deliveryFee = $settings->deliveryFeeForCategory($productCategory);
        }

        if (! $installationFromBundle && $installationFee <= 0) {
            $installationFee = max(0, (float) ($settings->installation_flat_addon ?? 0));
        }

        return [
            'delivery_fee' => round($deliveryFee, 2),
            'installation_fee' => round($installationFee, 2),
            'inspection_fee_from_bundle' => round($inspectionFromBundle, 2),
        ];
    }

    /**
     * Invoice fees from bundle custom_services (Bundle Mgt → Invoice tab).
     * Fee names should contain delivery / installation / inspection / material.
     *
     * @return array{delivery_fee: float, installation_fee: float, inspection_fee: float, material_cost: float}
     */
    public static function resolveBundleInvoiceFeesFromCustomServices(
        ?Bundles $bundle,
        string $checkoutFlow = 'buy_now',
        ?string $installerChoice = 'troosolar',
        bool $includeInstallationMaterial = false,
    ): array {
        $result = [
            'delivery_fee' => 0.0,
            'installation_fee' => 0.0,
            'inspection_fee' => 0.0,
            'material_cost' => 0.0,
        ];

        if (! $bundle) {
            return $result;
        }

        $bundle->loadMissing('customServices');
        $services = $bundle->customServices;
        $flowServices = $services->filter(function ($svc) use ($checkoutFlow) {
            $flow = $svc->flow_type ?? 'buy_now';

            return $flow === $checkoutFlow;
        });
        if ($checkoutFlow === 'bnpl' && $flowServices->isEmpty()) {
            $flowServices = $services->filter(fn ($svc) => ($svc->flow_type ?? 'buy_now') === 'buy_now');
        }

        foreach ($flowServices as $svc) {
            $rawTitle = (string) ($svc->title ?? '');
            if (str_starts_with($rawTitle, '[OL]')
                || str_starts_with($rawTitle, '[OL:TROOSOLAR]')
                || str_starts_with($rawTitle, '[OL:OWN]')) {
                continue;
            }

            $visibility = 'both';
            if (str_starts_with($rawTitle, '[FEE:TROOSOLAR]')) {
                $visibility = 'troosolar';
            } elseif (str_starts_with($rawTitle, '[FEE:OWN]')) {
                $visibility = 'own';
            } else {
                $cleanForVis = $rawTitle;
                foreach (['[FEE:TROOSOLAR]', '[FEE:OWN]', '[FEE]'] as $prefix) {
                    if (str_starts_with($cleanForVis, $prefix)) {
                        $cleanForVis = trim(substr($cleanForVis, strlen($prefix)));
                        break;
                    }
                }
                $lower = strtolower($cleanForVis);
                if (str_contains($lower, 'material')) {
                    $visibility = 'own';
                } elseif (str_contains($lower, 'installation fee') || str_contains($lower, 'inspection fee')) {
                    $visibility = 'troosolar';
                }
            }

            $visible = match ($visibility) {
                'troosolar' => $installerChoice !== 'own',
                'own' => $installerChoice === 'own',
                default => true,
            };
            if (! $visible) {
                continue;
            }

            $clean = $rawTitle;
            foreach (['[FEE:TROOSOLAR]', '[FEE:OWN]', '[FEE]'] as $prefix) {
                if (str_starts_with($clean, $prefix)) {
                    $clean = trim(substr($clean, strlen($prefix)));
                    break;
                }
            }
            $name = strtolower($clean);
            $amount = LegacyInvoiceFees::effectiveAmount((float) ($svc->service_amount ?? 0), 'installation');
            if (str_contains($name, 'delivery')) {
                $amount = LegacyInvoiceFees::effectiveAmount((float) ($svc->service_amount ?? 0), 'delivery');
            } elseif (str_contains($name, 'inspection')) {
                $amount = LegacyInvoiceFees::effectiveAmount((float) ($svc->service_amount ?? 0), 'inspection');
            } elseif (str_contains($name, 'material')) {
                if ($installerChoice === 'own' && ! $includeInstallationMaterial) {
                    continue;
                }
                $amount = (float) ($svc->service_amount ?? 0);
            } elseif (str_contains($name, 'installation')) {
                $amount = LegacyInvoiceFees::effectiveAmount((float) ($svc->service_amount ?? 0), 'installation');
            } else {
                continue;
            }

            if ($amount <= 0) {
                continue;
            }

            if (str_contains($name, 'material')) {
                $result['material_cost'] += $amount;
            } elseif (str_contains($name, 'delivery')) {
                $result['delivery_fee'] += $amount;
            } elseif (str_contains($name, 'inspection')) {
                $result['inspection_fee'] += $amount;
            } elseif (str_contains($name, 'installation')) {
                $result['installation_fee'] += $amount;
            }
        }

        foreach (array_keys($result) as $key) {
            $result[$key] = round($result[$key], 2);
        }

        return $result;
    }
}
