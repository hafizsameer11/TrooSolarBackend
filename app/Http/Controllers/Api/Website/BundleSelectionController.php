<?php

namespace App\Http\Controllers\Api\Website;

use App\Http\Controllers\Controller;
use App\Models\Bundles;
use App\Models\CalculatorSetting;
use App\Models\Material;
use App\Models\BundleMaterial;
use App\Helpers\ResponseHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Exception;

class BundleSelectionController extends Controller
{
    /**
     * Get bundles by type (Inverter + Battery or Solar+Inverter+Battery)
     * GET /api/bundles/type/{type}
     */
    public function getBundlesByType($type)
    {
        try {
            $slugify = function (string $value): string {
                return trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)), '-');
            };

            $defaults = CalculatorSetting::defaults();
            $configuredTypes = (CalculatorSetting::where('is_active', true)->first()?->bundle_types)
                ?: ($defaults['bundle_types'] ?? []);

            $bundleType = collect($configuredTypes)->first(function ($configuredType) use ($type, $slugify) {
                return is_string($configuredType) && $slugify($configuredType) === $type;
            });

            if (!$bundleType) {
                $bundleType = $type === 'inverter-battery'
                    ? 'Inverter + Battery'
                    : 'Solar+Inverter+Battery';
            }

            $bundles = Bundles::where('bundle_type', $bundleType)
                ->when(Schema::hasColumn('bundles', 'is_available'), function ($q) {
                    $q->where('is_available', true);
                })
                ->with(['bundleMaterials.material.category'])
                ->orderByDisplayProminence()
                ->orderBy('total_price')
                ->get()
                ->map(function ($bundle) {
                    return [
                        'id' => $bundle->id,
                        'title' => $bundle->title,
                        'bundle_type' => $bundle->bundle_type,
                        'is_available' => (bool) ($bundle->is_available ?? true),
                        'top_deal' => (bool) ($bundle->top_deal ?? false),
                        'is_most_popular' => (bool) ($bundle->is_most_popular ?? false),
                        'featured_image' => $bundle->featured_image_url ?? $bundle->featured_image ?? null,
                        'total_price' => (float) $bundle->total_price,
                        'discount_price' => $bundle->discount_price ? (float) $bundle->discount_price : null,
                        'inver_rating' => $bundle->inver_rating,
                        'total_output' => $bundle->total_output,
                        'total_load' => $bundle->total_load,
                        'materials_count' => $bundle->bundleMaterials->count(),
                        'materials' => $bundle->bundleMaterials->map(function ($bm) {
                            return [
                                'id' => $bm->material->id,
                                'name' => $bm->material->name,
                                'quantity' => (float) $bm->quantity,
                                'unit' => $bm->material->unit,
                                'category' => $bm->material->category->name ?? null,
                            ];
                        }),
                    ];
                });

            return ResponseHelper::success($bundles, 'Bundles fetched successfully.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to fetch bundles.', 500);
        }
    }

    /**
     * Get single bundle with all materials and fees
     * GET /api/bundles/{id}/details
     */
    public function getBundleDetails($id)
    {
        try {
            $bundle = Bundles::with([
                'bundleItems.product.category',
                'bundleMaterials.material.category',
                'customServices',
            ])->when(Schema::hasColumn('bundles', 'is_available'), function ($q) {
                $q->where('is_available', true);
            })->find($id);

            if (!$bundle) {
                return ResponseHelper::error('Bundle not found.', 404);
            }

            // Get installation and delivery fees from custom_services
            $installationFee = 0;
            $deliveryFee = 0;
            $inspectionFee = 0;

            foreach ($bundle->customServices as $svc) {
                $svcTitle = $svc->title ?? '';
                if (str_contains($svcTitle, 'Installation Fees')) {
                    $installationFee = (float) ($svc->service_amount ?? 0);
                } elseif (str_contains($svcTitle, 'Delivery Fees')) {
                    $deliveryFee = (float) ($svc->service_amount ?? 0);
                } elseif (str_contains($svcTitle, 'Inspection Fees')) {
                    $inspectionFee = (float) ($svc->service_amount ?? 0);
                }
            }

            $bundleItems = $bundle->bundleItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product' => $item->product ? [
                        'id' => $item->product->id,
                        'title' => $item->product->title,
                        'price' => (float) ($item->product->price ?? 0),
                        'discount_price' => $item->product->discount_price ? (float) $item->product->discount_price : null,
                        'featured_image' => $item->product->featured_image_url ?? $item->product->featured_image ?? null,
                        'category' => $item->product->category ? [
                            'id' => $item->product->category->id,
                            'title' => $item->product->category->title,
                        ] : null,
                    ] : null,
                    'quantity' => $item->quantity ?? 1,
                    'rate_override' => $item->rate_override !== null ? (float) $item->rate_override : null,
                ];
            });

            $bundleMaterials = $bundle->bundleMaterials->map(function ($bm) {
                return [
                    'id' => $bm->id,
                    'material_id' => $bm->material_id,
                    'material' => $bm->material ? [
                        'id' => $bm->material->id,
                        'name' => $bm->material->name,
                        'unit' => $bm->material->unit,
                        'rate' => (float) ($bm->material->rate ?? 0),
                        'selling_rate' => (float) ($bm->material->selling_rate ?? 0),
                        'warranty' => $bm->material->warranty,
                        'category' => $bm->material->category ? [
                            'id' => $bm->material->category->id,
                            'name' => $bm->material->category->name,
                        ] : null,
                    ] : null,
                    'quantity' => (float) ($bm->quantity ?? 1),
                    'rate_override' => $bm->rate_override !== null ? (float) $bm->rate_override : null,
                ];
            });

            $customServices = $bundle->customServices->map(function ($service) {
                $row = [
                    'id' => $service->id,
                    'title' => $service->title,
                    'service_amount' => (float) ($service->service_amount ?? 0),
                ];
                if (Schema::hasColumn('custom_services', 'quantity')) {
                    $row['quantity'] = (int) max(1, (int) ($service->quantity ?? 1));
                    $row['unit'] = ($service->unit !== null && (string) $service->unit !== '') ? (string) $service->unit : 'Nos';
                    $row['quantity_applies'] = (bool) ($service->quantity_applies ?? true);
                }

                if (Schema::hasColumn('custom_services', 'flow_type')) {
                    $row['flow_type'] = in_array($service->flow_type, ['buy_now', 'bnpl'], true)
                        ? $service->flow_type
                        : 'buy_now';
                }

                return $row;
            });

            $response = [
                'id' => $bundle->id,
                'title' => $bundle->title,
                'bundle_type' => $bundle->bundle_type,
                'top_deal' => (bool) ($bundle->top_deal ?? false),
                'is_most_popular' => (bool) ($bundle->is_most_popular ?? false),
                'total_price' => (float) $bundle->total_price,
                'discount_price' => $bundle->discount_price ? (float) $bundle->discount_price : null,
                'inver_rating' => $bundle->inver_rating,
                'total_output' => $bundle->total_output,
                'total_load' => $bundle->total_load,
                'detailed_description' => $bundle->detailed_description ?? null,
                'product_model' => $bundle->product_model ?? null,
                'what_is_inside_bundle_text' => $bundle->what_is_inside_bundle_text ?? null,
                'what_bundle_powers_text' => $bundle->what_bundle_powers_text ?? null,
                'backup_time_description' => $bundle->backup_time_description ?? null,
                'system_capacity_display' => $bundle->system_capacity_display ?? null,
                'specifications' => $bundle->specifications ?? null,
                'featured_image' => $bundle->featured_image ?? null,
                'featured_image_url' => $bundle->featured_image_url ?? null,
                'fees' => [
                    'installation_fee' => $installationFee,
                    'delivery_fee' => $deliveryFee,
                    'inspection_fee' => $inspectionFee,
                ],
                'bundle_items' => $bundleItems,
                'bundle_materials' => $bundleMaterials,
                'custom_services' => $customServices,
                'materials' => $bundle->bundleMaterials->map(function ($bm) {
                    return [
                        'id' => $bm->material->id,
                        'name' => $bm->material->name,
                        'quantity' => (float) $bm->quantity,
                        'unit' => $bm->material->unit,
                        'rate' => (float) ($bm->material->rate ?? 0),
                        'selling_rate' => (float) ($bm->material->selling_rate ?? 0),
                        'warranty' => $bm->material->warranty,
                        'category' => [
                            'id' => $bm->material->category->id ?? null,
                            'name' => $bm->material->category->name ?? null,
                        ],
                    ];
                }),
            ];

            return ResponseHelper::success($response, 'Bundle details fetched successfully.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to fetch bundle details.', 500);
        }
    }

    /**
     * Get materials by category for custom builder
     * GET /api/bundles/materials/category/{categoryId}
     */
    public function getMaterialsByCategory($categoryId)
    {
        try {
            $materials = Material::where('material_category_id', $categoryId)
                ->where('is_active', true)
                ->with('category')
                ->orderBy('name')
                ->get()
                ->map(function ($material) {
                    return [
                        'id' => $material->id,
                        'name' => $material->name,
                        'unit' => $material->unit,
                        'rate' => (float) ($material->rate ?? 0),
                        'selling_rate' => (float) ($material->selling_rate ?? 0),
                        'warranty' => $material->warranty,
                        'category' => [
                            'id' => $material->category->id,
                            'name' => $material->category->name,
                        ],
                    ];
                });

            return ResponseHelper::success($materials, 'Materials fetched successfully.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to fetch materials.', 500);
        }
    }

    /**
     * Calculate custom bundle price
     * POST /api/bundles/custom/calculate
     */
    public function calculateCustomBundle(Request $request)
    {
        try {
            $validated = $request->validate([
                'materials' => 'required|array',
                'materials.*.material_id' => 'required|exists:materials,id',
                'materials.*.quantity' => 'required|numeric|min:0.01',
            ]);

            $totalPrice = 0;
            $materials = [];

            foreach ($validated['materials'] as $mat) {
                $material = Material::with('category')->find($mat['material_id']);
                if (!$material) continue;

                $quantity = (float) $mat['quantity'];
                $unitPrice = (float) ($material->selling_rate ?? $material->rate ?? 0);
                $subtotal = $unitPrice * $quantity;

                $materials[] = [
                    'material_id' => $material->id,
                    'name' => $material->name,
                    'quantity' => $quantity,
                    'unit' => $material->unit,
                    'unit_price' => $unitPrice,
                    'subtotal' => $subtotal,
                    'category' => $material->category->name ?? null,
                ];

                $totalPrice += $subtotal;
            }

            return ResponseHelper::success([
                'total_price' => $totalPrice,
                'materials' => $materials,
                'materials_count' => count($materials),
            ], 'Custom bundle calculated successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to calculate custom bundle.', 500);
        }
    }
}
