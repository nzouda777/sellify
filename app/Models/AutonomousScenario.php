<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AutonomousScenario extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'name',
        'location_label',
        'faker_locale',
        'window_start_time',
        'window_end_time',
        'min_interval_seconds',
        'max_interval_seconds',
        'min_quantity',
        'max_quantity',
        'fulfill_orders',
        'promo_code',
        'promo_discount_percentage',
        'min_amount',
        'max_amount',
        'target_orders',
        'generated_orders',
        'is_active',
        'next_run_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'next_run_at' => 'datetime',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'promo_discount_percentage' => 'decimal:2',
        'target_orders' => 'integer',
        'generated_orders' => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withTimestamps()
            ->withPivot('weight');
    }
}
