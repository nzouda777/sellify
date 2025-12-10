<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'shopify_product_id',
        'shopify_variant_id',
        'title',
        'variant_title',
        'sku',
        'price',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'price' => 'decimal:2',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function autonomousScenarios(): BelongsToMany
    {
        return $this->belongsToMany(AutonomousScenario::class)
            ->withTimestamps()
            ->withPivot('weight');
    }
}
