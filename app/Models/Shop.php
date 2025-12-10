<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use App\Models\ShopSyncWindow;

class Shop extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'shopify_domain',
        'access_token',
        'timezone',
        'auto_sync_enabled',
        'auto_sync_mode',
        'status',
        'scopes',
    ];

    protected $casts = [
        'auto_sync_enabled' => 'boolean',
        'scopes' => 'array',
        'access_token' => 'encrypted',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps()->withPivot('role');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function syncWindows(): HasMany
    {
        return $this->hasMany(ShopSyncWindow::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function autonomousScenarios(): HasMany
    {
        return $this->hasMany(AutonomousScenario::class);
    }

    public function isWithinSyncWindow(Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now($this->timezone ?? 'UTC');
        if ($this->syncWindows()->count() === 0) {
            return true;
        }

        foreach ($this->syncWindows as $window) {
            $start = Carbon::parse($window->window_start_time, $now->timezone);
            $end = Carbon::parse($window->window_end_time, $now->timezone);
            if ($end->lessThan($start)) {
                $end->addDay();
            }
            if ($now->between($start, $end)) {
                return true;
            }
        }

        return false;
    }
}
