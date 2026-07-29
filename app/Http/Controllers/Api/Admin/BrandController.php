<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BrandRequest;
use App\Models\Brand;
use App\Helpers\ResponseHelper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Product;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class BrandController extends Controller
{
    private function resolveCategoryIds(Request $request): array
    {
        $ids = $request->input('category_ids', []);
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $ids);
        }
        if (!is_array($ids)) {
            $ids = [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id) => (int) $id,
            $ids
        ))));

        if ($ids === [] && $request->filled('category_id')) {
            $ids = [(int) $request->input('category_id')];
        }

        return $ids;
    }

    private function formatBrand(Brand $brand): array
    {
        $brand->loadMissing('categories');
        $payload = $brand->toArray();
        $payload['category_ids'] = $brand->categories->pluck('id')->values()->all();
        $payload['category_names'] = $brand->categories
            ->map(fn ($c) => $c->title ?? $c->name ?? '')
            ->filter()
            ->values()
            ->all();

        return $payload;
    }

    private function syncBrandCategories(Brand $brand, array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        $brand->categories()->sync($categoryIds);
        if (!$brand->category_id) {
            $brand->category_id = $categoryIds[0];
            $brand->save();
        }
    }

    public function index()
    {
        try {
            $query = Brand::with('categories');
            if (Schema::hasColumn('brands', 'sort_order')) {
                $query->orderByRaw('COALESCE(sort_order, id) asc');
            } else {
                $query->orderBy('title');
            }
            $brands = $query
                ->get()
                ->map(fn (Brand $brand) => $this->formatBrand($brand))
                ->values();

            return ResponseHelper::success($brands, 'Brands fetched.');
        } catch (Exception $e) {
            Log::error('Error fetching brands: ' . $e->getMessage());
            return ResponseHelper::error('Something went wrong.', 500);
        }
    }

    /**
     * POST /brands/reorder
     * Body: { "orders": [ { "id": 5, "sort_order": 1 }, ... ] }
     */
    public function reorder(Request $request)
    {
        try {
            if (! Schema::hasColumn('brands', 'sort_order')) {
                return ResponseHelper::error('Brand ordering is not available yet. Run migrations.', 500);
            }

            $entries = $request->input('orders', []);
            if (! is_array($entries) || empty($entries)) {
                return ResponseHelper::error('Invalid payload.', 422);
            }

            foreach ($entries as $entry) {
                $id = $entry['id'] ?? null;
                $order = $entry['sort_order'] ?? null;
                if ($id === null || $order === null) {
                    continue;
                }
                Brand::where('id', (int) $id)->update(['sort_order' => (int) $order]);
            }

            return ResponseHelper::success(null, 'Brand order updated.');
        } catch (Exception $e) {
            Log::error('Error reordering brands: ' . $e->getMessage());

            return ResponseHelper::error('Failed to reorder brands.', 500, $e->getMessage());
        }
    }

    public function store(BrandRequest $request)
    {
        try {
            $data = $request->validated();

            try {
                if ($request->hasFile('icon')) {
                    $file = $request->file('icon');
                    $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $data['icon'] = Storage::url($file->storeAs('public/brands', $name));
                }
            } catch (Exception $e) {
                Log::error('Error storing icon: ' . $e->getMessage());
                return ResponseHelper::error('Failed to upload brand icon.', 500);
            }

            $brand = Brand::create($data);
            if (Schema::hasColumn('brands', 'sort_order') && ($brand->sort_order === null)) {
                $max = (int) Brand::query()->max('sort_order');
                $brand->sort_order = $max + 1;
                $brand->save();
            }
            $this->syncBrandCategories($brand, $this->resolveCategoryIds($request));

            return ResponseHelper::success($this->formatBrand($brand->fresh()), 'Brand created.', 201);
        } catch (Exception $e) {
            Log::error('Error creating brand: ' . $e->getMessage());
            return ResponseHelper::error('Failed to create brand.', 500);
        }
    }

    public function show($id)
    {
        try {
            $brand = Brand::find($id);
            if (!$brand) {
                return ResponseHelper::error('Brand not found.', 404);
            }

            return ResponseHelper::success($this->formatBrand($brand->load('categories')), 'Brand found.');
        } catch (Exception $e) {
            Log::error('Error showing brand: ' . $e->getMessage());
            return ResponseHelper::error('Something went wrong.', 500);
        }
    }

    public function update(BrandRequest $request, $id)
    {
        try {
            $brand = Brand::find($id);
            if (!$brand) return ResponseHelper::error('Brand not found.', 404);

            $data = $request->validated();

            try {
                if ($request->hasFile('icon')) {
                    if ($brand->icon) {
                        try {
                            $old = str_replace('/storage/', 'public/', $brand->icon);
                            Storage::delete($old);
                        } catch (Exception $e) {
                            Log::warning('Old icon could not be deleted: ' . $e->getMessage());
                        }
                    }

                    $file = $request->file('icon');
                    $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
                    $data['icon'] = Storage::url($file->storeAs('public/brands', $name));
                }
            } catch (Exception $e) {
                Log::error('Error handling icon update: ' . $e->getMessage());
                return ResponseHelper::error('Failed to update brand icon.', 500);
            }

            $brand->update($data);
            $categoryIds = $this->resolveCategoryIds($request);
            if ($categoryIds !== []) {
                $this->syncBrandCategories($brand, $categoryIds);
            }

            return ResponseHelper::success($this->formatBrand($brand->fresh()), 'Brand updated.');
        } catch (Exception $e) {
            Log::error('Error updating brand: ' . $e->getMessage());
            return ResponseHelper::error('Failed to update brand.', 500);
        }
    }

    public function destroy($id)
    {
        try {
            $brand = Brand::find($id);
            if (!$brand) return ResponseHelper::error('Brand not found.', 404);

            try {
                if ($brand->icon) {
                    $old = str_replace('/storage/', 'public/', $brand->icon);
                    Storage::delete($old);
                }
            } catch (Exception $e) {
                Log::warning('Could not delete brand icon: ' . $e->getMessage());
            }

            $brand->delete();
            return ResponseHelper::success(null, 'Brand deleted.');
        } catch (Exception $e) {
            Log::error('Error deleting brand: ' . $e->getMessage());
            return ResponseHelper::error('Failed to delete brand.', 500);
        }
    }




    public function getByCategory($categoryId)
    {
        try {
            // Verify category exists
            $category = \App\Models\Category::find($categoryId);
            if (!$category) {
                return ResponseHelper::error('Category not found.', 404);
            }

            // Auto-sync missing brand tags for products in this category.
            // This prevents brand filter from breaking when product.brand_id is null.
            $this->syncMissingBrandTagsForCategory((int) $categoryId);

            // Return brands linked to this category (pivot or legacy) or with products in category
            $query = Brand::with('categories')
                ->where(function ($q) use ($categoryId) {
                    $q->where('category_id', $categoryId)
                        ->orWhereHas('categories', function ($cq) use ($categoryId) {
                            $cq->where('categories.id', $categoryId);
                        })
                        ->orWhereHas('products', function ($pq) use ($categoryId) {
                            $pq->where('category_id', $categoryId)
                                ->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0');
                        });
                });
            if (Schema::hasColumn('brands', 'sort_order')) {
                $query->orderByRaw('COALESCE(sort_order, id) asc');
            } else {
                $query->orderBy('title');
            }
            $brands = $query
                ->get()
                ->unique('id')
                ->values()
                ->map(fn (Brand $brand) => $this->formatBrand($brand));

            return ResponseHelper::success($brands, $brands->isEmpty()
                ? 'No brands found for this category.'
                : 'Brands fetched by category.');
        } catch (Exception $e) {
            Log::error('Error fetching brands by category: ' . $e->getMessage());
            return ResponseHelper::error('Failed to fetch brands by category.', 500);
        }
    }

    private function syncMissingBrandTagsForCategory(int $categoryId): void
    {
        $brands = Brand::query()->select(['id', 'title', 'category_id'])->get();
        if ($brands->isEmpty()) {
            return;
        }

        $products = Product::query()
            ->where('category_id', $categoryId)
            ->get(['id', 'title', 'brand_id']);

        if ($products->isEmpty()) {
            return;
        }

        $brandsById = $brands->keyBy('id');
        $sortedBrands = $brands
            ->filter(fn ($b) => trim((string) $b->title) !== '')
            ->sortByDesc(fn ($b) => strlen((string) $b->title))
            ->values();

        foreach ($products as $product) {
            $title = trim(strtolower((string) $product->title));
            if ($title === '') {
                continue;
            }

            $matchedBrand = $sortedBrands->first(function ($brand) use ($title) {
                    $needle = trim(strtolower((string) $brand->title));
                    return $needle !== '' && str_contains($title, $needle);
                });

            if (!$matchedBrand) {
                continue;
            }

            $currentBrand = $product->brand_id ? $brandsById->get((int) $product->brand_id) : null;
            $currentBrandTitle = trim(strtolower((string) ($currentBrand->title ?? '')));
            $isCurrentBrandMissing = !$currentBrand;
            $isCurrentBrandOutsideCategory = false;
            if ($currentBrand) {
                $currentBrand->loadMissing('categories');
                $linkedToCategory = $currentBrand->categories
                    ->contains(fn ($c) => (int) $c->id === $categoryId);
                $isCurrentBrandOutsideCategory =
                    !$linkedToCategory && (int) ($currentBrand->category_id ?? 0) !== $categoryId;
            }
            $isCurrentBrandNotInTitle = $currentBrandTitle === '' || !str_contains($title, $currentBrandTitle);
            $needsRetag = $isCurrentBrandMissing || $isCurrentBrandOutsideCategory || $isCurrentBrandNotInTitle;

            if ($needsRetag && (int) $product->brand_id !== (int) $matchedBrand->id) {
                $product->brand_id = $matchedBrand->id;
                $product->save();
            }
        }
    }



public function showBrandByCategory($categoryId, $brandId)
{
    try {
        $brand = Brand::with('categories')
            ->where('id', $brandId)
            ->where(function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId)
                    ->orWhereHas('categories', function ($cq) use ($categoryId) {
                        $cq->where('categories.id', $categoryId);
                    });
            })
            ->first();

        if (!$brand) {
            return ResponseHelper::error('Brand not found in this category.', 404);
        }

        return ResponseHelper::success($this->formatBrand($brand), 'Brand fetched successfully.');
    } catch (\Exception $e) {
        return ResponseHelper::error('Failed to fetch brand.', 500, $e->getMessage());
    }
}


}
