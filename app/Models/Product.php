<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'category_id',
        'brand_id',
        'price',
        'discount_price',
        'discount_end_date',
        'stock',
        'installation_price',
        'top_deal',
        'is_most_popular',
        'installation_compulsory',
        'is_available',
        'featured_image',
        'description',
        'specifications',
        'old_quantity',
        // ❌ This line does nothing in $fillable and also conflicts with relation name:
        // 'images' => 'array',
    ];

    protected $casts = [
        'discount_end_date' => 'date',
        'top_deal' => 'boolean',
        'is_most_popular' => 'boolean',
        'installation_compulsory' => 'boolean',
        'is_available' => 'boolean',
        'price' => 'double',
        'discount_price' => 'double',
        'installation_price' => 'double',
        // If you keep a JSON column named `images`, cast it here instead:
        // 'images' => 'array',
    ];
    protected $appends = ['featured_image_url'];


    /**
     * Relationship: Product belongs to a Category
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relationship: Product belongs to a Brand
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }
    public function images()
    {
        return $this->hasMany(\App\Models\ProductImage::class);
    }

    public function details()
    {
        return $this->hasMany(\App\Models\ProductDetail::class);
    }

    // loan calculation relationship
    public function loanCalculations()
    {
        return $this->belongsToMany(LoanCalculation::class, 'loan_calculation_product');
    }
    public function reviews()
    {
        return $this->hasMany(ProductReveiews::class);
    }
    public function getFeaturedImageUrlAttribute(): ?string
    {
        return \App\Support\PublicMedia::toPublicUrl($this->featured_image);
    }

    /**
     * Public listings: top deals first, then highly recommended, then by id (newer ids last in DESC order).
     */
    public function scopeOrderByDisplayProminence(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        if (Schema::hasColumn($table, 'top_deal')) {
            $query->orderByDesc($table . '.top_deal');
        }
        if (Schema::hasColumn($table, 'is_most_popular')) {
            $query->orderByDesc($table . '.is_most_popular');
        }

        return $query->orderByDesc($table . '.id');
    }
}
