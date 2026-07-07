<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomService extends Model
{
    use HasFactory;

    protected $fillable = [
        'bundle_id',
        'flow_type',
        'title',
        'service_amount',
        'quantity',
        'unit',
        'quantity_applies',
    ];

    protected $casts = [
        'service_amount' => 'float',
        'quantity' => 'integer',
        'quantity_applies' => 'boolean',
    ];

    public function bundle()
{
    return $this->belongsTo(Bundles::class);
}


}

