<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Product;
use App\Helpers\ResponseHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Exception;

class CategoryController extends Controller
{
    public function index()
    {
        try {
            $categories = Category::query()
                ->orderByRaw('COALESCE(sort_order, id) asc')
                ->get();
            return ResponseHelper::success($categories, 'Categories fetched.');
        } catch (Exception $e) {
            return ResponseHelper::error('Something went wrong.', 500, $e->getMessage());
        }
    }

    public function store(CategoryRequest $request)
    {
        try {
            $data = $request->validated();

            if ($request->hasFile('icon')) {
                $file = $request->file('icon');
                $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $data['icon'] = Storage::url($file->storeAs('public/icons', $name));
            }

            $category = Category::create($data);
            \App\Support\ShopQuantityFeeTiers::bootstrapCategoryTiers(
                (int) $category->id,
                (string) ($category->title ?? '')
            );
            return ResponseHelper::success($category, 'Category created.', 201);
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to create category.', 500, $e->getMessage());
        }
    }

    public function show($id)
    {
        try {
            $category = Category::find($id);
            return $category
                ? ResponseHelper::success($category, 'Category found.')
                : ResponseHelper::error('Category not found.', 404);
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to fetch category.', 500, $e->getMessage());
        }
    }

    public function update(CategoryRequest $request, $id)
    {
        try {
            $category = Category::find($id);
            if (!$category) return ResponseHelper::error('Category not found.', 404);

            $data = $request->validated();

            if ($request->hasFile('icon')) {
                if ($category->icon) {
                    $old = str_replace('/storage/', 'public/', $category->icon);
                    Storage::delete($old);
                }

                $file = $request->file('icon');
                $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $data['icon'] = Storage::url($file->storeAs('public/icons', $name));
            }

            $category->update($data);
            return ResponseHelper::success($category, 'Category updated.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to update category.', 500, $e->getMessage());
        }
    }

    /**
     * POST /categories/reorder
     * Body: { "orders": [ { "id": 5, "sort_order": 1 }, ... ] }
     */
    public function reorder(Request $request)
    {
        try {
            $entries = $request->input('orders', []);
            if (!is_array($entries) || empty($entries)) {
                return ResponseHelper::error('Invalid payload.', 422);
            }

            foreach ($entries as $entry) {
                $id = $entry['id'] ?? null;
                $order = $entry['sort_order'] ?? null;
                if ($id === null || $order === null) {
                    continue;
                }
                Category::where('id', (int) $id)->update(['sort_order' => (int) $order]);
            }

            return ResponseHelper::success(null, 'Category order updated.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to reorder categories.', 500, $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            $category = Category::find($id);
            if (!$category) return ResponseHelper::error('Category not found.', 404);

            if ($category->icon) {
                $old = str_replace('/storage/', 'public/', $category->icon);
                Storage::delete($old);
            }

            $category->delete();
            return ResponseHelper::success(null, 'Category deleted.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to delete category.', 500, $e->getMessage());
        }
    }



/**
 * Return products that belong to this category (by product.category_id).
 * Previously used category->brands->products; now uses category->products so
 * inverters show under Inverters, panels under Panels, etc.
 */
public function getProducts($id)
{
    try {
        $category = Category::find($id);

        if (!$category) {
            return ResponseHelper::error('Category not found.', 404);
        }

        $query = $category->products()
            ->where('category_id', $category->id)
            ->when(Schema::hasColumn('products', 'is_available'), function ($q) {
                $q->where('is_available', true);
            })
            ->with(['details', 'images', 'reviews']);

        // Support both numeric stock and textual values (e.g. "In Stock").
        $normalizedStockExpr = DB::raw("LOWER(REPLACE(REPLACE(REPLACE(TRIM(stock), ' ', ''), '-', ''), '_', ''))");
        $query->where(function (Builder $q) use ($normalizedStockExpr) {
            $q->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0')
                ->orWhereIn($normalizedStockExpr, ['instock', 'available', 'true', 'yes']);
        });

        $query->orderByDisplayProminence();

        $products = $query->get();

        // Self-heal for categories where products were uploaded under materials/wrong category.
        if ($products->isEmpty() && $this->isAllInOneCategoryTitle((string) $category->title)) {
            $this->syncAllInOneProductsForCategory((int) $category->id);

            $retry = $category->products()
                ->where('category_id', $category->id)
                ->when(Schema::hasColumn('products', 'is_available'), function ($q) {
                    $q->where('is_available', true);
                })
                ->with(['details', 'images', 'reviews']);

            $retry->where(function (Builder $q) use ($normalizedStockExpr) {
                $q->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0')
                    ->orWhereIn($normalizedStockExpr, ['instock', 'available', 'true', 'yes']);
            });

            $products = $retry->get();
        }

        if ($products->isEmpty() && $this->isAccessoriesCategoryTitle((string) $category->title)) {
            $this->syncAccessoriesProductsForCategory((int) $category->id);

            $retry = $category->products()
                ->where('category_id', $category->id)
                ->when(Schema::hasColumn('products', 'is_available'), function ($q) {
                    $q->where('is_available', true);
                })
                ->with(['details', 'images', 'reviews']);

            $retry->where(function (Builder $q) use ($normalizedStockExpr) {
                $q->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0')
                    ->orWhereIn($normalizedStockExpr, ['instock', 'available', 'true', 'yes']);
            });

            $retry->orderByDisplayProminence();

            $products = $retry->get();
        }

        return ResponseHelper::success($products, 'Products fetched by category.');
    } catch (\Exception $e) {
        return ResponseHelper::error($e->getMessage(), 500);
    }
}


private function isAllInOneCategoryTitle(string $title): bool
{
    $name = strtolower(trim($title));
    return str_contains($name, 'all in one')
        || str_contains($name, 'all-in-one')
        || str_contains($name, 'aio')
        || str_contains($name, 'system');
}

private function isAllInOneProductTitle(string $title): bool
{
    $name = strtolower(trim($title));
    if ($name === '') {
        return false;
    }

    return str_contains($name, 'all in one')
        || str_contains($name, 'all-in-one')
        || str_contains($name, 'aio')
        || (str_contains($name, 'kva') && str_contains($name, 'kwh'));
}

private function syncAllInOneProductsForCategory(int $categoryId): void
{
    Product::query()
        ->select(['id', 'title', 'category_id'])
        ->get()
        ->filter(fn ($product) => $this->isAllInOneProductTitle((string) ($product->title ?? '')))
        ->each(function ($product) use ($categoryId) {
            if ((int) $product->category_id !== $categoryId) {
                $product->category_id = $categoryId;
                $product->save();
            }
        });

    $allInOneMaterialCategoryIds = MaterialCategory::query()
        ->where('is_active', true)
        ->where(function ($q) {
            $q->where('code', 'C')
                ->orWhereRaw('LOWER(name) like ?', ['%all in one%'])
                ->orWhereRaw('LOWER(name) like ?', ['%all-in-one%'])
                ->orWhereRaw('LOWER(name) like ?', ['%aio%'])
                ->orWhereRaw('LOWER(name) like ?', ['%system%']);
        })
        ->pluck('id')
        ->all();

    if (empty($allInOneMaterialCategoryIds)) {
        return;
    }

    $defaultBrand = Brand::query()->where('category_id', $categoryId)->first();
    if (!$defaultBrand) {
        $defaultBrand = Brand::first();
    }
    if (!$defaultBrand) {
        return;
    }

    $materials = Material::query()
        ->whereIn('material_category_id', $allInOneMaterialCategoryIds)
        ->where('is_active', true)
        ->get();

        foreach ($materials as $material) {
            if (!$this->isAllInOneProductTitle((string) ($material->name ?? ''))) {
                continue;
            }

            $existing = Product::query()->where('title', $material->name)->first();
            $price = \App\Support\ProductMaterialPricing::priceFromMaterial($material, $existing);

            Product::updateOrCreate(
                ['title' => $material->name],
                [
                    'category_id' => $categoryId,
                    'brand_id' => $defaultBrand->id,
                    'price' => $price,
                    'discount_price' => $price,
                'stock' => 'In Stock',
                'installation_price' => 0.00,
                'top_deal' => false,
                'installation_compulsory' => false,
                'is_available' => true,
                'featured_image' => 'https://api.troosolar.com/storage/products/e212b55b-057a-4a39-8d80-d241169cdac0.png',
            ]
        );
    }
}

private function isAccessoriesCategoryTitle(string $title): bool
{
    $name = strtolower(trim($title));
    return str_contains($name, 'accessor');
}

private function syncAccessoriesProductsForCategory(int $categoryId): void
{
    $accessoryMaterialCategoryIds = MaterialCategory::query()
        ->where('is_active', true)
        ->where(function ($q) {
            $q->where('code', 'S')
                ->orWhereRaw('LOWER(name) like ?', ['%accessor%']);
        })
        ->pluck('id')
        ->all();

    if (empty($accessoryMaterialCategoryIds)) {
        return;
    }

    $materials = Material::query()
        ->whereIn('material_category_id', $accessoryMaterialCategoryIds)
        ->where('is_active', true)
        ->get();

    if ($materials->isEmpty()) {
        return;
    }

    // Retag already-existing products that match accessory material titles.
    $materialNames = $materials->pluck('name')->filter()->values()->all();
    if (!empty($materialNames)) {
        Product::query()
            ->whereIn('title', $materialNames)
            ->where('category_id', '!=', $categoryId)
            ->update(['category_id' => $categoryId]);
    }

    $defaultBrand = Brand::query()->where('category_id', $categoryId)->first();
    if (!$defaultBrand) {
        $defaultBrand = Brand::first();
    }
    if (!$defaultBrand) {
        return;
    }

    foreach ($materials as $material) {
        $existing = Product::query()->where('title', $material->name)->first();
        $price = \App\Support\ProductMaterialPricing::priceFromMaterial($material, $existing);

        Product::updateOrCreate(
            ['title' => $material->name],
            [
                'category_id' => $categoryId,
                'brand_id' => $defaultBrand->id,
                'price' => $price,
                'discount_price' => $price,
                'stock' => 'In Stock',
                'installation_price' => 0.00,
                'top_deal' => false,
                'installation_compulsory' => false,
                'is_available' => true,
                'featured_image' => 'https://api.troosolar.com/storage/products/e212b55b-057a-4a39-8d80-d241169cdac0.png',
            ]
        );
    }
}

}
