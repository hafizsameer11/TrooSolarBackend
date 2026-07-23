<?php

namespace App\Support;

use App\Models\Bundles;
use App\Models\CheckoutSetting;
use App\Models\DeliveryLocation;
use App\Models\State;
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
    public static function insuranceAmountFromPercent(float $itemsSubtotal, float $installationFull, float $percent): int
    {
        if ($percent <= 0) {
            return 0;
        }

        return (int) round(max(0, $itemsSubtotal) * ($percent / 100.0));
    }

    /**
     * Sum admin category inspection fees for distinct product categories in the cart.
     */
    public static function inspectionTotalFromCartItems(Collection $cartItems, CheckoutSetting $settings): int
    {
        $keys = [];
        foreach ($cartItems as $item) {
            $model = $item->itemable ?? null;
            if (! $model instanceof \App\Models\Product) {
                continue;
            }
            $model->loadMissing('category');
            $key = CheckoutSetting::inferProductFeeCategory($model);
            if ($key) {
                $keys[] = $key;
            }
        }

        return (int) round($settings->sumProductCategoryFees($keys, 'inspection'));
    }

    public static function vatAmount(float $taxableBase, float $vatPercent): int
    {
        if ($vatPercent <= 0 || $taxableBase <= 0) {
            return 0;
        }

        return (int) round($taxableBase * ($vatPercent / 100.0));
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
        $settings ??= CheckoutSetting::get();

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
