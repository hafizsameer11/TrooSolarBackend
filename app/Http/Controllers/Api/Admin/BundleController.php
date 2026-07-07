<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BundleRequest;
use App\Models\Bundles;
use App\Models\BundleItems;
use App\Models\BundleMaterial;
use App\Models\BundleCustomAppliance;
use App\Models\CustomService;
use App\Models\Product;
use App\Helpers\ResponseHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BundleController extends Controller
{


public function index(Request $request)
{
    try {
        $query = $request->query('q'); // accept ?q=1080 or any number
        $kvaQuery = $request->query('kva');
        $bundleType = $request->query('bundle_type');
        $includeUnavailable = $request->boolean('include_unavailable', false);
        $isAdmin = strtolower((string) (auth()->user()->role ?? '')) === 'admin';

        // If no query parameter => return all bundles
        if (empty($query)) {
            $bundlesQuery = Bundles::with(['bundleItems.product.category', 'bundleMaterials.material.category', 'customServices', 'brand']);
            if (Schema::hasColumn('bundles', 'is_available') && !($includeUnavailable && $isAdmin)) {
                $bundlesQuery->where('is_available', true);
            }
            if (!empty($bundleType)) {
                $bundlesQuery->where('bundle_type', $bundleType);
            }
            $bundles = $bundlesQuery->orderByDisplayProminence()->get();
            $formatted = $bundles->map(fn($b) => $this->formatBundleResponse($b))->values();
            return ResponseHelper::success($formatted, 'Bundles fetched.');
        }

        // Ensure query is a number
        if (!is_numeric($query)) {
            return ResponseHelper::error('Query parameter q must be numeric.', 422);
        }

        $q = (float) $query;

        // Fetch all bundles with relations
        $bundlesQuery = Bundles::with(['bundleItems.product.category', 'bundleMaterials.material.category', 'customServices', 'brand']);
        if (!empty($bundleType)) {
            $bundlesQuery->where('bundle_type', $bundleType);
        }
        if (Schema::hasColumn('bundles', 'is_available') && !($includeUnavailable && $isAdmin)) {
            $bundlesQuery->where('is_available', true);
        }
        $bundles = $bundlesQuery->get();

        if ($bundles->isEmpty()) {
            return ResponseHelper::error('No bundles found.', 404);
        }

        // Parse load/rating to watts (e.g. "1.5kVA" => 1500, "1200 W" => 1200, "1.2" => 1200)
        $parseToWatts = function ($value) {
            if ($value === null || $value === '') {
                return 0.0;
            }

            $valueStr = trim((string) $value);
            $numeric = 0.0;

            $normalized = preg_replace('/[,\s]+/', '', $valueStr);
            if ($normalized !== null && is_numeric($normalized)) {
                $numeric = (float) $normalized;
            } elseif (preg_match('/([\d.]+)/', $valueStr, $m)) {
                $numeric = (float) $m[1];
            } else {
                return 0.0;
            }

            $lower = strtolower($valueStr);
            if (strpos($lower, 'kva') !== false || strpos($lower, 'kw') !== false || strpos($lower, 'kwh') !== false) {
                return $numeric * 1000;
            }
            if (strpos($lower, 'w') !== false) {
                return $numeric;
            }

            return $numeric < 100 ? $numeric * 1000 : $numeric;
        };

        $bundles = $bundles->map(function ($bundle) use ($parseToWatts) {
            // For load-calculator recommendations, compare by inverter rating first.
            $bundle->_parsed_capacity_watts = $parseToWatts($bundle->inver_rating ?? null);
            if ($bundle->_parsed_capacity_watts <= 0) {
                $bundle->_parsed_capacity_watts = $parseToWatts($bundle->total_load ?? null);
            }
            return $bundle;
        })->filter(fn($b) => ((float) ($b->_parsed_capacity_watts ?? 0)) > 0)->values();

        if ($bundles->isEmpty()) {
            return ResponseHelper::error('No bundles with valid inverter/load capacity found.', 404);
        }

        // Helper: parse numeric kVA from strings like "4", "4kVA/24V", "3.6".
        $parseKva = function ($value) {
            if ($value === null || $value === '') return null;
            $valueStr = trim((string) $value);
            if (preg_match('/([\d.]+)/', $valueStr, $m)) {
                $num = (float) $m[1];
                return $num > 0 ? $num : null;
            }
            return null;
        };

        // Exact match first
        $exact = $bundles->filter(function ($bundle) use ($q) {
            return abs(((float) ($bundle->_parsed_capacity_watts ?? 0)) - $q) < 0.0001;
        })->values();

        if (!$exact->isEmpty()) {
            $selected = $exact->sortBy('_parsed_capacity_watts')->values();
        } else {
            // Prefer closest bundle(s) at or above the target.
            $above = $bundles->filter(fn($bundle) => ((float) ($bundle->_parsed_capacity_watts ?? 0)) >= $q)->values();

            if (!$above->isEmpty()) {
                $minAboveDelta = $above->map(fn($bundle) => ((float) ($bundle->_parsed_capacity_watts ?? 0)) - $q)->min();
                $selected = $above->filter(function ($bundle) use ($q, $minAboveDelta) {
                    $delta = ((float) ($bundle->_parsed_capacity_watts ?? 0)) - $q;
                    return abs($delta - $minAboveDelta) < 0.0001;
                })->sortBy('_parsed_capacity_watts')->values();
            } else {
                // If all bundles are below target, use nearest overall
                $closestDelta = $bundles->map(fn($bundle) => abs(((float) ($bundle->_parsed_capacity_watts ?? 0)) - $q))->min();
                $selected = $bundles->filter(function ($bundle) use ($q, $closestDelta) {
                    $delta = abs(((float) ($bundle->_parsed_capacity_watts ?? 0)) - $q);
                    return abs($delta - $closestDelta) < 0.0001;
                })->sortBy('_parsed_capacity_watts')->values();
            }
        }

        // Pair recommendations:
        // - if requested/proposed is 3.6kVA, include 4.0kVA bundles too (and vice versa)
        // - if requested/proposed is 6.0kVA, include 6.5kVA bundles too (and vice versa)
        // This is only applied for these groups; other recommendations remain unchanged.
        $requestedKva = $parseKva($kvaQuery);
        if ($requestedKva === null && !$selected->isEmpty()) {
            $requestedKva = $parseKva($selected->first()->inver_rating ?? null);
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
            $extra = $bundles->filter(function ($bundle) use ($parseKva, $groupKvaStrs) {
                $kva = $parseKva($bundle->inver_rating ?? null);
                if ($kva === null) return false;
                $kvaStr = number_format(round($kva, 1), 1, '.', '');
                return in_array($kvaStr, $groupKvaStrs, true);
            })->values();

            if (!$extra->isEmpty()) {
                $selected = $selected->concat($extra)->unique('id')->values();
            }
        }

        $formatted = $selected->map(fn($b) => $this->formatBundleResponse($b))->values();
        return ResponseHelper::success($formatted, 'Closest bundle fetched.');
    } catch (Exception $e) {
        Log::error('Error fetching bundles: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
        return ResponseHelper::error('Failed to fetch bundles.', 500);
    }
}


    public function store(BundleRequest $request)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();

            $featuredImagePath = null;
        if ($request->hasFile('featured_image')) {
            $featuredImagePath = $request->file('featured_image')->store('bundles', 'public');
        }
            // Calculate total from products
            $totalProductPrice = 0;
            if (!empty($data['items'])) {
                $products = Product::whereIn('id', $data['items'])->get();
                $totalProductPrice = $products->sum('price');
            }

            // Calculate total from custom services
            $customServiceAmount = 0;
            if (!empty($data['custom_services'])) {
                foreach ($data['custom_services'] as $service) {
                    $customServiceAmount += $service['service_amount'] ?? 0;
                }
            }

            $calculatedTotal = $totalProductPrice + $customServiceAmount;

            $totalPrice = $data['total_price'] ?? $calculatedTotal;
            $discountPrice = $data['discount_price'] ?? ($totalPrice * 0.90); // 20% discount

            $safeTrim = function ($v) {
                if ($v === null || $v === '') return $v === '' ? '' : null;
                return is_string($v) ? trim($v) : $v;
            };

            $createData = [
                'title' => $safeTrim($data['title'] ?? null),
                'total_price' => $totalPrice,
                'discount_price' => $discountPrice,
                'discount_end_date' => isset($data['discount_end_date']) && $data['discount_end_date'] !== '' ? $data['discount_end_date'] : null,
            ];
            if (Schema::hasColumn('bundles', 'brand_id')) {
                $createData['brand_id'] = isset($data['brand_id']) && $data['brand_id'] !== '' ? (int) $data['brand_id'] : null;
            }
            if (Schema::hasColumn('bundles', 'featured_image')) {
                $createData['featured_image'] = $featuredImagePath;
            }
            if (Schema::hasColumn('bundles', 'bundle_type')) {
                $createData['bundle_type'] = $safeTrim($data['bundle_type'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'is_available')) {
                $createData['is_available'] = array_key_exists('is_available', $data)
                    ? (bool) $data['is_available']
                    : true;
            }
            if (Schema::hasColumn('bundles', 'top_deal')) {
                $createData['top_deal'] = array_key_exists('top_deal', $data)
                    ? (bool) $data['top_deal']
                    : false;
            }
            if (Schema::hasColumn('bundles', 'is_most_popular')) {
                $createData['is_most_popular'] = array_key_exists('is_most_popular', $data)
                    ? (bool) $data['is_most_popular']
                    : false;
            }

            if (Schema::hasColumn('bundles', 'product_model')) {
                $createData['product_model'] = $safeTrim($data['product_model'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'system_capacity_display')) {
                $createData['system_capacity_display'] = $safeTrim($data['system_capacity_display'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'detailed_description')) {
                $createData['detailed_description'] = $safeTrim($data['detailed_description'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'what_is_inside_bundle_text')) {
                $createData['what_is_inside_bundle_text'] = $safeTrim($data['what_is_inside_bundle_text'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'what_bundle_powers_text')) {
                $createData['what_bundle_powers_text'] = $safeTrim($data['what_bundle_powers_text'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'backup_time_description')) {
                $createData['backup_time_description'] = $safeTrim($data['backup_time_description'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'total_load')) {
                $createData['total_load'] = $safeTrim($data['total_load'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'inver_rating')) {
                $createData['inver_rating'] = $safeTrim($data['inver_rating'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'total_output')) {
                $createData['total_output'] = $safeTrim($data['total_output'] ?? null);
            }
            if (Schema::hasColumn('bundles', 'specifications') && array_key_exists('specifications', $data)) {
                $createData['specifications'] = is_array($data['specifications']) ? $data['specifications'] : null;
            }

            $bundle = Bundles::create($createData);

            if (!empty($data['custom_appliances']) && Schema::hasTable('bundle_custom_appliances')) {
                foreach ($data['custom_appliances'] as $appliance) {
                    $name = isset($appliance['name']) && is_string($appliance['name']) ? trim($appliance['name']) : '';
                    BundleCustomAppliance::create([
                        'bundle_id' => $bundle->id,
                        'name' => $name,
                        'wattage' => (float) ($appliance['wattage'] ?? 0),
                        'quantity' => (int) ($appliance['quantity'] ?? 1),
                        'estimated_daily_hours_usage' => isset($appliance['estimated_daily_hours_usage']) ? (float) $appliance['estimated_daily_hours_usage'] : null,
                    ]);
                }
            }

            if (!empty($data['items_detail'])) {
                foreach ($data['items_detail'] as $itemDetail) {
                    BundleItems::create([
                        'bundle_id'     => $bundle->id,
                        'product_id'    => $itemDetail['product_id'],
                        'quantity'      => $itemDetail['quantity'] ?? 1,
                        'rate_override' => isset($itemDetail['rate_override']) && $itemDetail['rate_override'] !== '' ? $itemDetail['rate_override'] : null,
                    ]);
                }
            } elseif (!empty($data['items'])) {
                foreach ($data['items'] as $productId) {
                    BundleItems::create([
                        'bundle_id'  => $bundle->id,
                        'product_id' => $productId,
                    ]);
                }
            }

            if (!empty($data['custom_services'])) {
                foreach ($data['custom_services'] as $service) {
                    CustomService::create($this->buildCustomServiceRow($bundle->id, $service));
                }
            }

            DB::commit();
            $relations = ['bundleItems.product.category', 'customServices', 'bundleMaterials.material.category', 'brand'];
            if (Schema::hasTable('bundle_custom_appliances')) {
                $relations[] = 'customAppliances';
            }
            return ResponseHelper::success(
                $this->formatBundleResponse($bundle->load($relations)),
                'Bundle created.',
                201
            );
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error creating bundle: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ResponseHelper::error('Failed to create bundle.', 500);
        }
    }

    public function show($id)
    {
        try {
            $bundle = Bundles::with([
                'bundleItems.product.category',
                'customServices',
                'bundleMaterials.material.category',
                'customAppliances',
                'brand',
            ])->find($id);
            
            if (!$bundle) {
                return ResponseHelper::error('Bundle not found.', 404);
            }

            // Format bundle items (from products)
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

            // Format bundle materials (from materials table)
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
                            'code' => $bm->material->category->code,
                        ] : null,
                    ] : null,
                    'quantity' => (float) ($bm->quantity ?? 1),
                    'rate_override' => $bm->rate_override !== null ? (float) $bm->rate_override : null,
                ];
            });

            // Format custom services
            $customServices = $bundle->customServices->map(function ($service) {
                return $this->serializeCustomServiceForApi($service);
            });

            // Format custom appliances
            $customAppliances = $bundle->customAppliances->map(function ($a) {
                return [
                    'id' => $a->id,
                    'bundle_id' => $a->bundle_id,
                    'name' => $a->name,
                    'wattage' => (float) $a->wattage,
                    'quantity' => (int) ($a->quantity ?? 1),
                    'estimated_daily_hours_usage' => $a->estimated_daily_hours_usage !== null ? (float) $a->estimated_daily_hours_usage : null,
                ];
            });

            // Build response
            $response = [
                'id' => $bundle->id,
                'brand_id' => $bundle->brand_id,
                'brand' => $bundle->brand ? ['id' => $bundle->brand->id, 'title' => $bundle->brand->title] : null,
                'title' => $bundle->title,
                'featured_image' => $bundle->featured_image,
                'bundle_type' => $bundle->bundle_type,
                'is_available' => $bundle->is_available,
                'total_price' => (float) ($bundle->total_price ?? 0),
                'discount_price' => $bundle->discount_price ? (float) $bundle->discount_price : null,
                'discount_end_date' => $bundle->discount_end_date,
                'inver_rating' => $bundle->inver_rating,
                'total_output' => $bundle->total_output,
                'total_load' => $bundle->total_load,
                'product_model' => $bundle->product_model,
                'system_capacity_display' => $bundle->system_capacity_display,
                'detailed_description' => $bundle->detailed_description,
                'what_is_inside_bundle_text' => $bundle->what_is_inside_bundle_text,
                'what_bundle_powers_text' => $bundle->what_bundle_powers_text,
                'backup_time_description' => $bundle->backup_time_description,
                'specifications' => $bundle->specifications ?? null,
                'created_at' => $bundle->created_at?->toIso8601String(),
                'updated_at' => $bundle->updated_at?->toIso8601String(),
                'featured_image_url' => $bundle->featured_image_url,
                'bundle_items' => $bundleItems,
                'bundle_materials' => $bundleMaterials,
                'custom_services' => $customServices,
                'custom_appliances' => $customAppliances,
            ];

            return ResponseHelper::success($response, 'Bundle found.');
        } catch (Exception $e) {
            Log::error('Error fetching bundle: ' . $e->getMessage());
            return ResponseHelper::error('Failed to fetch bundle.', 500);
        }
    }

    public function update(BundleRequest $request, $id)
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();
            $bundle = Bundles::find($id);

            if (!$bundle) {
                return ResponseHelper::error('Bundle not found.', 404);
            }

             // Handle featured image upload
        if ($request->hasFile('featured_image')) {
            // Delete old image if exists
            if ($bundle->featured_image && Storage::disk('public')->exists($bundle->featured_image)) {
                Storage::disk('public')->delete($bundle->featured_image);
            }

            // Store new image
            $path = $request->file('featured_image')->store('bundles', 'public');
            $data['featured_image'] = $path;
        } else {
            unset($data['featured_image']); // Prevent accidental overwrite with null
        }
            // Recalculate prices if not provided
            $totalProductPrice = 0;
            if (!empty($data['items'])) {
                $products = Product::whereIn('id', $data['items'])->get();
                $totalProductPrice = $products->sum('price');
            }

            $customServiceAmount = 0;
            if (!empty($data['custom_services'])) {
                foreach ($data['custom_services'] as $service) {
                    $customServiceAmount += $service['service_amount'] ?? 0;
                }
            }

            $calculatedTotal = $totalProductPrice + $customServiceAmount;

            $totalPrice = $data['total_price'] ?? $calculatedTotal;
            $discountPrice = $data['discount_price'] ?? ($totalPrice * 0.80);

            $updatePayload = [
                'title' => $data['title'] ?? $bundle->title,
                'featured_image' => $data['featured_image'] ?? $bundle->featured_image,
                'bundle_type' => $data['bundle_type'] ?? $bundle->bundle_type,
                'is_available' => array_key_exists('is_available', $data) ? (bool) $data['is_available'] : $bundle->is_available,
                'product_model' => array_key_exists('product_model', $data) ? trim($data['product_model'] ?? '') : $bundle->product_model,
                'system_capacity_display' => array_key_exists('system_capacity_display', $data) ? trim($data['system_capacity_display'] ?? '') : $bundle->system_capacity_display,
                'detailed_description' => array_key_exists('detailed_description', $data) ? trim($data['detailed_description'] ?? '') : $bundle->detailed_description,
                'what_is_inside_bundle_text' => array_key_exists('what_is_inside_bundle_text', $data) ? trim($data['what_is_inside_bundle_text'] ?? '') : $bundle->what_is_inside_bundle_text,
                'what_bundle_powers_text' => array_key_exists('what_bundle_powers_text', $data) ? trim($data['what_bundle_powers_text'] ?? '') : $bundle->what_bundle_powers_text,
                'backup_time_description' => array_key_exists('backup_time_description', $data) ? trim($data['backup_time_description'] ?? '') : $bundle->backup_time_description,
                'total_load' => array_key_exists('total_load', $data) ? trim($data['total_load'] ?? '') : $bundle->total_load,
                'inver_rating' => array_key_exists('inver_rating', $data) ? trim($data['inver_rating'] ?? '') : $bundle->inver_rating,
                'total_output' => array_key_exists('total_output', $data) ? trim($data['total_output'] ?? '') : $bundle->total_output,
                'total_price' => $totalPrice,
                'discount_price' => $discountPrice,
                'discount_end_date' => $data['discount_end_date'] ?? $bundle->discount_end_date,
            ];
            if (Schema::hasColumn('bundles', 'brand_id')) {
                $updatePayload['brand_id'] = array_key_exists('brand_id', $data)
                    ? ($data['brand_id'] === '' || $data['brand_id'] === null ? null : (int) $data['brand_id'])
                    : $bundle->brand_id;
            }
            if (Schema::hasColumn('bundles', 'top_deal')) {
                $updatePayload['top_deal'] = array_key_exists('top_deal', $data)
                    ? (bool) $data['top_deal']
                    : (bool) ($bundle->top_deal ?? false);
            }
            if (Schema::hasColumn('bundles', 'is_most_popular')) {
                $updatePayload['is_most_popular'] = array_key_exists('is_most_popular', $data)
                    ? (bool) $data['is_most_popular']
                    : (bool) ($bundle->is_most_popular ?? false);
            }
            $bundle->update($updatePayload);
            if (Schema::hasColumn('bundles', 'specifications') && array_key_exists('specifications', $data)) {
                $bundle->specifications = is_array($data['specifications']) ? $data['specifications'] : null;
                $bundle->save();
            }

            if (array_key_exists('custom_appliances', $data) && Schema::hasTable('bundle_custom_appliances')) {
                BundleCustomAppliance::where('bundle_id', $bundle->id)->delete();
                if (!empty($data['custom_appliances'])) {
                    foreach ($data['custom_appliances'] as $appliance) {
                        $name = isset($appliance['name']) && is_string($appliance['name']) ? trim($appliance['name']) : '';
                        BundleCustomAppliance::create([
                            'bundle_id' => $bundle->id,
                            'name' => $name,
                            'wattage' => (float) ($appliance['wattage'] ?? 0),
                            'quantity' => (int) ($appliance['quantity'] ?? 1),
                            'estimated_daily_hours_usage' => isset($appliance['estimated_daily_hours_usage']) ? (float) $appliance['estimated_daily_hours_usage'] : null,
                        ]);
                    }
                }
            }

            if (isset($data['items_detail'])) {
                BundleItems::where('bundle_id', $bundle->id)->delete();
                foreach ($data['items_detail'] as $itemDetail) {
                    BundleItems::create([
                        'bundle_id'     => $bundle->id,
                        'product_id'    => $itemDetail['product_id'],
                        'quantity'      => $itemDetail['quantity'] ?? 1,
                        'rate_override' => isset($itemDetail['rate_override']) && $itemDetail['rate_override'] !== '' ? $itemDetail['rate_override'] : null,
                    ]);
                }
            } elseif (isset($data['items'])) {
                BundleItems::where('bundle_id', $bundle->id)->delete();
                foreach ($data['items'] as $productId) {
                    BundleItems::create([
                        'bundle_id'  => $bundle->id,
                        'product_id' => $productId,
                    ]);
                }
            }

            if (isset($data['materials_detail'])) {
                BundleMaterial::where('bundle_id', $bundle->id)->delete();
                foreach ($data['materials_detail'] as $matDetail) {
                    BundleMaterial::create([
                        'bundle_id'     => $bundle->id,
                        'material_id'   => $matDetail['material_id'],
                        'quantity'      => $matDetail['quantity'] ?? 1,
                        'rate_override' => isset($matDetail['rate_override']) && $matDetail['rate_override'] !== '' ? $matDetail['rate_override'] : null,
                    ]);
                }
            }

            if (isset($data['custom_services'])) {
                CustomService::where('bundle_id', $bundle->id)->delete();
                foreach ($data['custom_services'] as $service) {
                    CustomService::create($this->buildCustomServiceRow($bundle->id, $service));
                }
            }

            DB::commit();
            $updateRelations = ['bundleItems.product.category', 'customServices', 'bundleMaterials.material.category', 'brand'];
            if (Schema::hasTable('bundle_custom_appliances')) {
                $updateRelations[] = 'customAppliances';
            }
            $bundle->loadMissing($updateRelations);

            return ResponseHelper::success(
                $this->formatBundleResponse($bundle),
                'Bundle updated successfully.'
            );
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error updating bundle: ' . $e->getMessage());
            return ResponseHelper::error('Failed to update bundle.', 500);
        }
    }

    /**
     * Format bundle for API response (single bundle with all relations).
     */
    private function formatBundleResponse(Bundles $bundle): array
    {
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
                    'category' => $item->product->category ? ['id' => $item->product->category->id, 'title' => $item->product->category->title] : null,
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
                    'category' => $bm->material->category ? ['id' => $bm->material->category->id, 'name' => $bm->material->category->name, 'code' => $bm->material->category->code] : null,
                ] : null,
                'quantity' => (float) ($bm->quantity ?? 1),
                'rate_override' => $bm->rate_override !== null ? (float) $bm->rate_override : null,
            ];
        });
        $customServices = $bundle->customServices->map(function ($s) {
            return $this->serializeCustomServiceForApi($s);
        });
        $customAppliances = Schema::hasTable('bundle_custom_appliances') && $bundle->relationLoaded('customAppliances')
            ? $bundle->customAppliances->map(function ($a) {
                return [
                    'id' => $a->id,
                    'bundle_id' => $a->bundle_id,
                    'name' => $a->name,
                    'wattage' => (float) $a->wattage,
                    'quantity' => (int) ($a->quantity ?? 1),
                    'estimated_daily_hours_usage' => $a->estimated_daily_hours_usage !== null ? (float) $a->estimated_daily_hours_usage : null,
                ];
            })
            : collect([]);
        return [
            'id' => $bundle->id,
            'brand_id' => $bundle->brand_id ?? null,
            'brand' => $bundle->relationLoaded('brand') && $bundle->brand
                ? ['id' => $bundle->brand->id, 'title' => $bundle->brand->title]
                : null,
            'title' => $bundle->title,
            'featured_image' => $bundle->featured_image,
            'featured_image_url' => $bundle->featured_image_url,
            'bundle_type' => $bundle->bundle_type,
            'is_available' => $bundle->is_available,
            'top_deal' => (bool) ($bundle->top_deal ?? false),
            'is_most_popular' => (bool) ($bundle->is_most_popular ?? false),
            'total_price' => (float) ($bundle->total_price ?? 0),
            'discount_price' => $bundle->discount_price ? (float) $bundle->discount_price : null,
            'discount_end_date' => $bundle->discount_end_date,
            'inver_rating' => $bundle->inver_rating,
            'total_output' => $bundle->total_output,
            'total_load' => $bundle->total_load,
            'product_model' => $bundle->product_model,
            'system_capacity_display' => $bundle->system_capacity_display,
            'detailed_description' => $bundle->detailed_description,
            'what_is_inside_bundle_text' => $bundle->what_is_inside_bundle_text,
            'what_bundle_powers_text' => $bundle->what_bundle_powers_text,
            'backup_time_description' => $bundle->backup_time_description,
            'specifications' => $bundle->specifications ?? null,
            'created_at' => $bundle->created_at?->toIso8601String(),
            'updated_at' => $bundle->updated_at?->toIso8601String(),
            'bundle_items' => $bundleItems,
            'bundle_materials' => $bundleMaterials,
            'custom_services' => $customServices,
            'custom_appliances' => $customAppliances,
        ];
    }

    public function destroy($id)
    {
        try {
            $bundle = Bundles::find($id);
            if (!$bundle) {
                return ResponseHelper::error('Bundle not found.', 404);
            }

            $bundle->delete();
            return ResponseHelper::success(null, 'Bundle deleted.');
        } catch (Exception $e) {
            Log::error('Error deleting bundle: ' . $e->getMessage());
            return ResponseHelper::error('Failed to delete bundle.', 500);
        }
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function buildCustomServiceRow(int $bundleId, array $service): array
    {
        $row = [
            'bundle_id' => $bundleId,
            'title' => $service['title'] ?? null,
            'service_amount' => $service['service_amount'] ?? 0,
        ];

        if (Schema::hasColumn('custom_services', 'flow_type')) {
            $flow = $service['flow_type'] ?? 'buy_now';
            $row['flow_type'] = in_array($flow, ['buy_now', 'bnpl'], true) ? $flow : 'buy_now';
        }

        if (!Schema::hasColumn('custom_services', 'quantity')) {
            return $row;
        }

        $qty = isset($service['quantity']) ? (int) $service['quantity'] : 1;
        $row['quantity'] = max(1, $qty);
        $unit = isset($service['unit']) && is_string($service['unit']) ? trim($service['unit']) : '';
        $row['unit'] = $unit !== '' ? mb_substr($unit, 0, 32) : 'Nos';

        $qa = $service['quantity_applies'] ?? true;
        if (is_string($qa)) {
            $row['quantity_applies'] = !in_array(strtolower(trim($qa)), ['0', 'false', 'no'], true);
        } else {
            $row['quantity_applies'] = (bool) $qa;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCustomServiceForApi(CustomService $service): array
    {
        $base = [
            'id' => $service->id,
            'title' => $service->title,
            'service_amount' => (float) ($service->service_amount ?? 0),
        ];
        if (Schema::hasColumn('custom_services', 'flow_type')) {
            $base['flow_type'] = in_array($service->flow_type, ['buy_now', 'bnpl'], true)
                ? $service->flow_type
                : 'buy_now';
        }
        if (!Schema::hasColumn('custom_services', 'quantity')) {
            return $base;
        }

        return array_merge($base, [
            'quantity' => (int) max(1, (int) ($service->quantity ?? 1)),
            'unit' => ($service->unit !== null && (string) $service->unit !== '') ? (string) $service->unit : 'Nos',
            'quantity_applies' => (bool) ($service->quantity_applies ?? true),
        ]);
    }
}