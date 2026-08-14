<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'icon',
        'has_method_selection',
        'sort_order',
        'show_on_store',
    ];

    protected $casts = [
        'has_method_selection' => 'boolean',
        'show_on_store' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function isShownOnStore(): bool
    {
        return $this->show_on_store !== false;
    }

    // Optional: Define relationship with brands
    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    // Optional: Define relationship with products (if needed)
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
