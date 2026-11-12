<?php

namespace App\Models;

use App\Support\PublicMedia;
use Illuminate\Database\Eloquent\Model;

class BundleImage extends Model
{
    protected $fillable = ['bundle_id', 'image'];

    protected $appends = ['image_url'];

    public function bundle()
    {
        return $this->belongsTo(Bundles::class, 'bundle_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        return PublicMedia::toPublicUrl($this->image);
    }
}
