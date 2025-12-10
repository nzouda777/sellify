<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSyncWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'window_start_time',
        'window_end_time',
        'label',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
