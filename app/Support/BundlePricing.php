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
     * BNPL catalog unit price.
     * bnpl_price = BNPL list price; bnpl_discount_price = BNPL sale price when set.
     * Falls back to Buy Now pricing when BNPL list is not set.
     */
    public static function bnplUnitPrice(Bundles $bundle): float
    {
        $bnplList = (float) ($bundle->bnpl_price ?? 0);
        if ($bnplList > 0) {
            $bnplSale = (float) ($bundle->bnpl_discount_price ?? 0);

            return $bnplSale > 0 && $bnplSale < $bnplList
                ? $bnplSale
                : $bnplList;
        }

        return self::buyNowUnitPrice($bundle);
    }

    public static function bnplListPrice(Bundles $bundle): float
    {
        $bnplList = (float) ($bundle->bnpl_price ?? 0);

        return $bnplList > 0 ? $bnplList : (float) ($bundle->total_price ?? 0);
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
        $bnplList = (float) ($row['bnpl_price'] ?? 0);
        if ($bnplList > 0) {
            $bnplSale = (float) ($row['bnpl_discount_price'] ?? 0);

            return $bnplSale > 0 && $bnplSale < $bnplList
                ? $bnplSale
                : $bnplList;
        }

        return self::buyNowUnitPriceFromArray($row);
    }

    public static function bnplListPriceFromArray(array $row): float
    {
        $bnplList = (float) ($row['bnpl_price'] ?? 0);

        return $bnplList > 0 ? $bnplList : (float) ($row['total_price'] ?? 0);
    }
}
