<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Brand;
use App\Models\BundleItems;
use App\Models\Bundles;
use App\Models\Category;
use App\Models\Product;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Product::with(['details', 'images','reviews.user']);
            $includeUnavailable = $request->boolean('include_unavailable', false);
            $includeOutOfStock = $request->boolean('include_out_of_stock', false);
            $isAdmin = strtolower((string) (auth()->user()->role ?? '')) === 'admin';
            if (Schema::hasColumn('products', 'is_available') && !($includeUnavailable && $isAdmin)) {
                $query->where('is_available', true);
            }
            if (!$isAdmin && !$includeOutOfStock) {
                $query->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0');
            }
            $products = $query->orderByDisplayProminence()->get();
            return ResponseHelper::success($products, 'Products fetched successfully.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to fetch products.', 500);
        }
    }
    public function topProducts()
    {
        try {
            $products = Product::where('top_deal', true)
                ->when(Schema::hasColumn('products', 'is_available'), function ($q) {
                    $q->where('is_available', true);
                })
                ->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0')
                ->with(['details', 'images','reviews.user'])
                ->get();
            return ResponseHelper::success($products, 'Top products fetched successfully.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to fetch top products.', 500, $e->getMessage());
        }
    }

  public function show(Request $request, $id)
{
    try {
        $includeUnavailable = $request->boolean('include_unavailable', false);
        $includeOutOfStock = $request->boolean('include_out_of_stock', false);
        $isAdmin = strtolower((string) (auth()->user()->role ?? '')) === 'admin';
        $product = Product::with(['details', 'images', 'reviews.user'])
            ->when(Schema::hasColumn('products', 'is_available') && !($includeUnavailable && $isAdmin), function ($q) {
                $q->where('is_available', true);
            })
            ->when(!$isAdmin && !$includeOutOfStock, function ($q) {
                $q->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0');
            })
            ->find($id);

        return $product
            ? ResponseHelper::success($product, 'Product fetched successfully.')
            : ResponseHelper::error('Product not found.', 404);
    } catch (Exception $e) {
        return ResponseHelper::error('Failed to fetch product.', 500, $e->getMessage());
    }
}

    public function store(ProductRequest $request)
    {
        try {
            $data = $this->processRequest($request);
            $product = Product::create($data);

            $this->handleImages($product, $request);
            $this->saveDetails($product, $request->product_details ?? []);
            return ResponseHelper::success(
                $product->refresh()->load(['details', 'images']),
                'Product created successfully.',
                201
            );
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to create product.', 500, $e->getMessage());
        }
    }

    public function update(ProductRequest $request, $id)
    {
        try {
            $product = Product::find($id);
            if (!$product) return ResponseHelper::error('Product not found.', 404);

            $data = $this->processRequest($request, $product);
            $product->update($data);

            $product->details()->delete();
            $this->deleteGalleryImages($product);

            $this->handleImages($product, $request);
            $this->saveDetails($product, $request->product_details ?? []);

            return ResponseHelper::success(
                $product->refresh()->load(['details', 'images']),
                'Product updated successfully.'
            );
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to update product.', 500, $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            $product = Product::find($id);
            if (!$product) return ResponseHelper::error('Product not found.', 404);

            // Bundles that include this product stay as rows after cascade of bundle_items —
            // hide empty / orphaned shells so they disappear from Buy Solar Bundles.
            $affectedBundleIds = BundleItems::query()
                ->where('product_id', $product->id)
                ->pluck('bundle_id')
                ->unique()
                ->filter()
                ->values()
                ->all();

            $this->deleteImage($product->featured_image);
            $this->deleteGalleryImages($product);
            $product->details()->delete();
            $product->delete();

            if (! empty($affectedBundleIds) && Schema::hasColumn('bundles', 'is_available')) {
                foreach ($affectedBundleIds as $bundleId) {
                    $bundle = Bundles::query()->withCount(['bundleItems', 'bundleMaterials'])->find($bundleId);
                    if (! $bundle) {
                        continue;
                    }
                    // No remaining shop products in the bundle → hide from customer catalog.
                    if ((int) ($bundle->bundle_items_count ?? 0) === 0) {
                        $bundle->is_available = false;
                        $bundle->save();
                    }
                }
            }

            return ResponseHelper::success(null, 'Product deleted successfully.');
        } catch (Exception $e) {
            return ResponseHelper::error('Failed to delete product.', 500, $e->getMessage());
        }
    }

    // ======================
    // HELPERS
    // ======================

    private function processRequest($request, $product = null)
    {
        $data = $request->validated();

        if ($request->hasFile('featured_image')) {
            if ($product) $this->deleteImage($product->featured_image);
            $data['featured_image'] = $this->uploadImage($request->file('featured_image'), 'products');
        }

        return $data;
    }

    private function uploadImage($file, $folder)
    {
        $name = Str::uuid() . '.' . $file->getClientOriginalExtension();
        return Storage::url($file->storeAs("public/{$folder}", $name));
    }

    private function deleteImage($url)
    {
        if ($url) {
            $path = str_replace('/storage/', 'public/', $url);
            if (Storage::exists($path)) Storage::delete($path);
        }
    }

    private function handleImages($product, $request)
    {
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $path = $this->uploadImage($file, 'products/gallery');
                $product->images()->create(['image' => $path]);
            }
        }
    }

    private function deleteGalleryImages($product)
    {
        foreach ($product->images as $img) {
            $this->deleteImage($img->image);
            $img->delete();
        }
    }

    private function saveDetails($product, $details)
    {
        foreach ($details as $item) {
            $text = is_array($item) ? ($item['detail'] ?? null) : $item;
            if (is_string($text) && trim($text)) {
                $product->details()->create(['detail' => $text]);
            }
        }
    }







public function getProductsByBrand($ids)
{
    try {
        $brandIds = array_values(array_filter(array_map(
            static fn ($id) => (int) $id,
            explode(',', (string) $ids)
        )));

        $brands = Brand::with([
            'categories',
            'products' => function ($query) {
                if (Schema::hasColumn('products', 'is_available')) {
                    $query->where('is_available', true);
                }
                $query->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0');
                $query->orderByDisplayProminence();
                $query->with('reviews.user');
            },
        ])->whereIn('id', $brandIds)->get();

        if ($brands->isEmpty()) {
            return ResponseHelper::error('No brands found.', 404);
        }

        $products = $brands->pluck('products')->flatten()->unique('id');

        $products = $products->filter(function ($product) use ($brands) {
            $brand = $brands->firstWhere('id', (int) $product->brand_id);
            if (!$brand) {
                return false;
            }

            $brand->loadMissing('categories');
            $allowedCategoryIds = $brand->categories->pluck('id');
            if ($allowedCategoryIds->isEmpty() && $brand->category_id) {
                $allowedCategoryIds = collect([(int) $brand->category_id]);
            }
            if ($allowedCategoryIds->isEmpty()) {
                return true;
            }

            return $allowedCategoryIds->contains((int) $product->category_id);
        })->values();

        return ResponseHelper::success($products, 'Products fetched by brand(s).');
    } catch (\Exception $e) {
        return ResponseHelper::error('Failed to fetch products.', 500, $e->getMessage());
    }
}


public function showProductByBrand($brandIds, $productId)
{
    try {
        $brandIds = explode(',', $brandIds);

        $product = Product::whereIn('brand_id', $brandIds)
                          ->where('id', $productId)
                          ->when(Schema::hasColumn('products', 'is_available'), function ($q) {
                              $q->where('is_available', true);
                          })
                          ->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0')
                          ->with(['details', 'images','reviews.user'])
                          ->first();

        if (!$product) {
            return ResponseHelper::error('Product not found under these brand(s).', 404);
        }

        return ResponseHelper::success($product, 'Product fetched successfully.');
    } catch (\Exception $e) {
        return ResponseHelper::error('Failed to fetch product.', 500, $e->getMessage());
    }
}




public function showProductByCategory(
    $categoryId, 
    $brandIds = null,
    $productId
) {
    try {
        $query = Product::where('category_id', $categoryId)
                    //   ->where('brand_id', $brandIds)
                        ->where('id', $productId)
                        ->when(Schema::hasColumn('products', 'is_available'), function ($q) {
                            $q->where('is_available', true);
                        })
                        ->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0')
                        ->with(['details', 'images', 'reviews.user']);

        if ($brandIds) {
            // $brandIdsArray = explode(',', $brandIds);
            $query->where('brand_id', $brandIds);
        }

        $product = $query->first();

        if (!$product) {
            return ResponseHelper::error('Product not found under this category/brand.', 404);
        }

        return ResponseHelper::success($product, 'Product found with full details2.');

    } catch (\Exception $e) {
        return ResponseHelper::error('Failed to fetch product.', 500, $e->getMessage());
    }
}
public function showProductByCategoryBrand(
    $categoryId, 
    $productId
) {
    try {
        $query = Product::where('category_id', $categoryId)
                        ->where('id', $productId)
                        ->when(Schema::hasColumn('products', 'is_available'), function ($q) {
                            $q->where('is_available', true);
                        })
                        ->whereRaw('CAST(stock AS DECIMAL(10,2)) > 0')
                        ->with(['details', 'images', 'reviews.user']);

        $product = $query->first();

        if (!$product) {
            return ResponseHelper::error('Product not found under this category/brand.', 404);
        }

        return ResponseHelper::success($product, 'Product found with category and full details.');

    } catch (\Exception $e) {
        return ResponseHelper::error('Failed to fetch product.', 500, $e->getMessage());
    }
}





}