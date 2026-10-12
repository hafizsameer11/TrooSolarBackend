<?php

namespace App\Http\Controllers\Api\Website;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Models\Bundles;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class BundleController extends Controller
{
    /**
     * Parse load/rating string to numeric in watts.
     * Handles: "1200 W" -> 1200, "3.8 kW" -> 3800, "1.2 kVA" -> 1200, "1.2" -> 1200.
     */
    private function parseLoadToWatts($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $valueStr = trim((string) $value);
        $numericValue = 0.0;

        // Extract numeric value
        $normalized = preg_replace('/[,\s]+/', '', $valueStr);
        if ($normalized !== null && is_numeric($normalized)) {
            $numericValue = (float) $normalized;
        } elseif (preg_match('/([\d.]+)/', $valueStr, $m)) {
            $numericValue = (float) $m[1];
        } else {
            return 0.0;
        }

        // Check for unit indicators and convert to watts
        $lowerValue = strtolower($valueStr);
        if (strpos($lowerValue, 'kva') !== false || strpos($lowerValue, 'kw') !== false || strpos($lowerValue, 'kwh') !== false) {
            // Value is in kW, convert to watts
            return $numericValue * 1000;
        } elseif (strpos($lowerValue, 'w') !== false) {
            // Value is already in watts
            return $numericValue;
        } else {
            // No unit specified - assume kW if value < 1000, otherwise assume watts
            // But based on your data (0.6, 1.2), these are likely kW
            if ($numericValue < 100) {
                // Likely kW (e.g., 0.6, 1.2, 3.8)
                return $numericValue * 1000;
            } else {
                // Likely already in watts
                return $numericValue;
            }
        }
    }

    /**
     * Resolve bundle usable capacity in watts.
     * For recommendation by inverter demand, prefer inverter rating first.
     * Fallback to total_load only when inverter rating is missing/zero.
     */
    private function resolveBundleCapacityWatts($bundle): float
    {
        $fromInverterRating = $this->parseLoadToWatts($bundle->inver_rating ?? null);
        if ($fromInverterRating > 0) {
            return $fromInverterRating;
        }

        return $this->parseLoadToWatts($bundle->total_load ?? null);
    }

    /**
     * Parse inverter rating string to numeric kVA (e.g., "3.6kVA/24V" -> 3.6).
     */
    private function parseInverterKva(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $valueStr = trim($value);
        if (preg_match('/([\d.]+)/', $valueStr, $m)) {
            $num = (float) $m[1];
            return $num > 0 ? $num : null;
        }
        return null;
    }

    /**
     * Within the same capacity tier, show top deals / highly recommended first.
     */
    private function compareBundlesByProminence($a, $b): int
    {
        if (Schema::hasColumn('bundles', 'top_deal')) {
            $ta = (int) (bool) ($a->top_deal ?? false);
            $tb = (int) (bool) ($b->top_deal ?? false);
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }
        }
        if (Schema::hasColumn('bundles', 'is_most_popular')) {
            $pa = (int) (bool) ($a->is_most_popular ?? false);
            $pb = (int) (bool) ($b->is_most_popular ?? false);
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
        }

        return strcmp((string) ($b->created_at ?? ''), (string) ($a->created_at ?? ''));
    }

    /**
     * Sort by parsed load (asc), then prominence / recency.
     */
    private function sortBundlesByCapacityThenProminence($bundles)
    {
        return $bundles->map(function ($bundle) {
            if (!isset($bundle->_parsed_load_watts)) {
                $bundle->_parsed_load_watts = $this->resolveBundleCapacityWatts($bundle);
            }

            return $bundle;
        })->sort(function ($a, $b) {
            $wa = (float) ($a->_parsed_load_watts ?? 0);
            $wb = (float) ($b->_parsed_load_watts ?? 0);
            if (abs($wa - $wb) >= 0.0001) {
                return $wa <=> $wb;
            }

            return $this->compareBundlesByProminence($a, $b);
        })->values();
    }

    /**
     * GET /api/bundles
     * Get all active bundles (Public endpoint for Buy Now flow).
     * When ?q={wattage} is present: treat q as target inverter capacity (in watts),
     * return exact-capacity matches first; if none, return closest-capacity bundles.
     */
    public function index(Request $request)
    {
        try {
            $query = $request->query('q');
            $bundleType = $request->query('bundle_type');
            // Only admins may request unavailable bundles (admin Bundle Mgt uses include_unavailable=1).
            // This route is public (no auth middleware), so resolve Sanctum user from Bearer token.
            $includeUnavailable = $request->boolean('include_unavailable', false);
            $authUser = $request->user();
            if (! $authUser && $request->bearerToken()) {
                $accessToken = PersonalAccessToken::findToken($request->bearerToken());
                $authUser = $accessToken?->tokenable;
            }
            $isAdmin = strtolower((string) ($authUser->role ?? '')) === 'admin';
            $allowUnavailable = $includeUnavailable && $isAdmin;

            $bundlesQuery = Bundles::with(['bundleItems.product', 'customServices', 'bundleMaterials.material.category'])
                ->orderByDisplayProminence();
            if (Schema::hasColumn('bundles', 'is_available') && ! $allowUnavailable) {
                $bundlesQuery->where('is_available', true);
            }
            if (!empty($bundleType)) {
                $bundlesQuery->where('bundle_type', $bundleType);
            }
            // Storefront catalog: hide empty / zero-price shells (admin Shop Mgt does the same).
            // Admin "include unavailable" list keeps zero-price rows so they can be cleaned up.
            if (! $allowUnavailable) {
                $bundlesQuery->where('total_price', '>', 0)
                    ->whereNotNull('title')
                    ->where('title', '!=', '');
            }
            $bundles = $bundlesQuery->get()->unique('id')->values();
            $allBundles = $bundles->values();

            // When q is provided (target capacity in watts), find exact/closest capacity bundles.
            if ($query !== null && $query !== '' && is_numeric($query)) {
                $targetCapacityWatts = (float) $query;

                $bundles = $bundles->map(function ($bundle) {
                    // Convert configured capacity to watts for comparison (total_load or inverter rating)
                    $bundle->_parsed_load_watts = $this->resolveBundleCapacityWatts($bundle);
                    return $bundle;
                });

                // Prefer exact match first (allow tiny float tolerance).
                $exact = $bundles->filter(function ($bundle) use ($targetCapacityWatts) {
                    return abs(((float) ($bundle->_parsed_load_watts ?? 0)) - $targetCapacityWatts) < 0.0001;
                })->values();

                if (!$exact->isEmpty()) {
                    $bundles = $this->sortBundlesByCapacityThenProminence($exact);
                } else {
                    // Prefer the closest bundle(s) at or above requested capacity.
                    $aboveOrEqual = $bundles
                        ->filter(function ($bundle) use ($targetCapacityWatts) {
                            return ((float) ($bundle->_parsed_load_watts ?? 0)) >= $targetCapacityWatts;
                        })
                        ->values();

                    if (!$aboveOrEqual->isEmpty()) {
                        $minAboveDelta = $aboveOrEqual
                            ->map(function ($bundle) use ($targetCapacityWatts) {
                                return ((float) ($bundle->_parsed_load_watts ?? 0)) - $targetCapacityWatts;
                            })
                            ->min();

                        $bundles = $this->sortBundlesByCapacityThenProminence(
                            $aboveOrEqual
                            ->filter(function ($bundle) use ($targetCapacityWatts, $minAboveDelta) {
                                $deltaAbove = ((float) ($bundle->_parsed_load_watts ?? 0)) - $targetCapacityWatts;
                                return abs($deltaAbove - $minAboveDelta) < 0.0001;
                            })
                            ->values()
                        );
                    } else {
                        // If everything is below target, return nearest overall.
                        $closestDelta = $bundles
                            ->map(function ($bundle) use ($targetCapacityWatts) {
                                return abs(((float) ($bundle->_parsed_load_watts ?? 0)) - $targetCapacityWatts);
                            })
                            ->min();

                        $bundles = $this->sortBundlesByCapacityThenProminence(
                            $bundles
                            ->filter(function ($bundle) use ($targetCapacityWatts, $closestDelta) {
                                $delta = abs(((float) ($bundle->_parsed_load_watts ?? 0)) - $targetCapacityWatts);
                                return abs($delta - $closestDelta) < 0.0001;
                            })
                            ->values()
                        );
                    }
                }

                // If we have at least one closest-capacity bundle, and the *requested/proposed* inverter kVA
                // falls into specific kVA groups (3.6/4.0 or 6.0/6.5), also include neighbours in that group.
                // The frontend passes ?kva=3.6 etc so this is deterministic.
                $requestedKva = $this->parseInverterKva($request->query('kva'));
                if ($requestedKva === null && !$bundles->isEmpty()) {
                    $primary = $bundles->first();
                    $requestedKva = $this->parseInverterKva($primary->inver_rating ?? null);
                }

                $groupKvaStrs = [];
                if ($requestedKva !== null) {
                    if (abs($requestedKva - 3.6) <= 0.2 || abs($requestedKva - 4.0) <= 0.2) {
                        $groupKvaStrs = ['3.6', '4.0'];
                    } elseif (abs($requestedKva - 6.0) <= 0.2 || abs($requestedKva - 6.5) <= 0.2) {
                        $groupKvaStrs = ['6.0', '6.5'];
                    }
                }

                if (!empty($groupKvaStrs)) {
                    $extra = $allBundles->filter(function ($bundle) use ($groupKvaStrs) {
                        $kva = $this->parseInverterKva($bundle->inver_rating ?? null);
                        if ($kva === null) {
                            return false;
                        }
                        // Normalize to 1-decimal string to avoid float strict-compare edge cases.
                        $kvaStr = number_format(round($kva, 1), 1, '.', '');
                        return in_array($kvaStr, $groupKvaStrs, true);
                    });

                    if (!$extra->isEmpty()) {
                        $bundles = $bundles->concat($extra)->unique('id')->values();
                    }
                }

                if (!$bundles->isEmpty()) {
                    $bundles = $this->sortBundlesByCapacityThenProminence($bundles);
                }
            } else {
                // All bundles (no load filter): smallest capacity first.
                $bundles = $this->sortBundlesByCapacityThenProminence($bundles);
            }

            $mapped = $bundles->map(function ($bundle) {
                $item = [
                    'id' => $bundle->id,
                    'title' => $bundle->title,
                    'bundle_type' => $bundle->bundle_type ?? null,
                    'featured_image' => $bundle->featured_image_url ?? $bundle->featured_image ?? null,
                    'total_price' => (float) ($bundle->total_price ?? 0),
                    'discount_price' => $bundle->discount_price ? (float) $bundle->discount_price : null,
                    'description' => $bundle->description ?? $bundle->detailed_description ?? null,
                    'is_active' => true,
                    'is_available' => (bool) ($bundle->is_available ?? true),
                    'top_deal' => (bool) ($bundle->top_deal ?? false),
                    'is_most_popular' => (bool) ($bundle->is_most_popular ?? false),
                    'total_load' => $bundle->total_load,
                    'total_output' => $bundle->total_output,
                    'inver_rating' => $bundle->inver_rating,
                    'created_at' => $bundle->created_at?->toIso8601String(),
                    'updated_at' => $bundle->updated_at?->toIso8601String(),
                ];
                // Remove temporary attribute if present
                if (isset($bundle->_parsed_load_watts)) {
                    unset($bundle->_parsed_load_watts);
                }
                return $item;
            });

            $payload = $mapped->values()->all();

            return ResponseHelper::success($payload, 'Bundles retrieved successfully');
        } catch (Exception $e) {
            Log::error('Error fetching bundles: ' . $e->getMessage());
            return ResponseHelper::error('Failed to fetch bundles', 500);
        }
    }
}
