<?php

namespace App\Support;

use App\Models\Bundles;

class BundlePricing
{
    /** Buy Now / cash catalog unit price (discount when set and > 0, else total). */
    public static function buyNowUnitPrice(Bundles $bundle): float
    {
        $discount = (float) ($bundle->discount_price ?? 0);

        return $discount > 0
            ? $discount
            : (float) ($bundle->total_price ?? 0);
    }

    /**
     * BNPL catalog unit price. Uses admin bnpl_price when set; otherwise Buy Now price.
     */
    public static function bnplUnitPrice(Bundles $bundle): float
    {
        $bnpl = (float) ($bundle->bnpl_price ?? 0);

        return $bnpl > 0 ? $bnpl : self::buyNowUnitPrice($bundle);
    }

    public static function buyNowUnitPriceFromArray(array $row): float
    {
        $discount = (float) ($row['discount_price'] ?? 0);

        return $discount > 0
            ? $discount
            : (float) ($row['total_price'] ?? 0);
    }

    public static function bnplUnitPriceFromArray(array $row): float
    {
        $bnpl = (float) ($row['bnpl_price'] ?? 0);

        return $bnpl > 0 ? $bnpl : self::buyNowUnitPriceFromArray($row);
    }
}
