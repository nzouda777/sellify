<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutonomousVisitScenario extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'name',
        'location_label',
        'faker_locale',
        'target_url',
        'window_start_time',
        'window_end_time',
        'min_interval_seconds',
        'max_interval_seconds',
        'target_visits',
        'generated_visits',
        'is_active',
        'next_run_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'next_run_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
