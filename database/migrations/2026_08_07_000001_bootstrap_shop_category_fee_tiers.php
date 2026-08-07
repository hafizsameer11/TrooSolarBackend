<?php

use App\Models\Category;
use App\Support\ShopQuantityFeeTiers;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! class_exists(Category::class)) {
            return;
        }

        Category::query()->get(['id', 'title'])->each(function ($category) {
            ShopQuantityFeeTiers::bootstrapCategoryTiers(
                (int) $category->id,
                (string) ($category->title ?? '')
            );
        });
    }

    public function down(): void
    {
        // No-op: tier rows may already be edited in admin.
    }
};
