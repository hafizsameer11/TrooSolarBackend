<?php

namespace App\Support;

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

    /** @return array<string, array<int, array{min: int, max: int|null, amount: float}>> */
    public static function defaultTierMap(): array
    {
        return [
            self::KEY_PANEL_DELIVERY => [
                ['min' => 1, 'max' => 4, 'amount' => 0],
                ['min' => 5, 'max' => 14, 'amount' => 0],
                ['min' => 15, 'max' => 24, 'amount' => 0],
                ['min' => 25, 'max' => null, 'amount' => 0],
            ],
            self::KEY_INVERTER_DELIVERY => [
                ['min' => 1, 'max' => 4, 'amount' => 0],
                ['min' => 5, 'max' => 14, 'amount' => 0],
                ['min' => 15, 'max' => 24, 'amount' => 0],
                ['min' => 25, 'max' => null, 'amount' => 0],
            ],
            self::KEY_BATTERY_DELIVERY => [
                ['min' => 1, 'max' => 4, 'amount' => 0],
                ['min' => 5, 'max' => 14, 'amount' => 0],
                ['min' => 15, 'max' => 24, 'amount' => 0],
                ['min' => 25, 'max' => null, 'amount' => 0],
            ],
            self::KEY_PANEL_INSTALLATION => self::defaultPanelInstallationTiers(),
            self::KEY_INVERTER_INSTALLATION => [
                ['min' => 1, 'max' => 1, 'amount' => 0],
                ['min' => 2, 'max' => 2, 'amount' => 0],
                ['min' => 3, 'max' => 3, 'amount' => 0],
                ['min' => 4, 'max' => null, 'amount' => 0],
            ],
            self::KEY_BATTERY_INSTALLATION => [
                ['min' => 1, 'max' => 1, 'amount' => 0],
                ['min' => 2, 'max' => 2, 'amount' => 0],
                ['min' => 3, 'max' => 3, 'amount' => 0],
                ['min' => 4, 'max' => null, 'amount' => 0],
            ],
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

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, array<int, array{min: int, max: int|null, amount: float}>>
     */
    public static function normalizeMap(?array $stored): array
    {
        $defaults = self::defaultTierMap();
        $stored = is_array($stored) ? $stored : [];
        $out = [];

        foreach ($defaults as $key => $defaultTiers) {
            $out[$key] = self::normalizeTierList(
                is_array($stored[$key] ?? null) ? $stored[$key] : $defaultTiers
            );
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
     */
    public static function sanitizePayload(array $map): array
    {
        $allowed = array_keys(self::defaultTierMap());
        $out = [];

        foreach ($allowed as $key) {
            if (! isset($map[$key]) || ! is_array($map[$key])) {
                continue;
            }
            $out[$key] = self::normalizeTierList($map[$key]);
        }

        return $out;
    }
}
