<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use function PHPSTORM_META\map;

class Brand extends Model
{
    use HasFactory;
    protected $fillable=[
        'title',
        'icon',
        'category_id',
        'sort_order',
    ];

    public function category(){
        return $this->belongsTo(Category::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'brand_category')->withTimestamps();
    }


    public function products()
{
    return $this->hasMany(\App\Models\Product::class);
}

}
