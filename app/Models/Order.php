<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Order extends Model
{
    // Constantes pour les statuts
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    const SYNC_NOT_SYNCED = 'not_synced';
    const SYNC_SYNCING = 'syncing';
    const SYNC_SYNCED = 'synced';
    const SYNC_FAILED = 'failed';

     protected $fillable = [
        'shop_id',
        'external_order_id',
        'customer_name',        // ✅ Renommé
        'customer_email',       // ✅ Renommé
        'customer_phone',       // ✅ Nouveau
        'amount',
        'currency',
        'quantity',
        'promo_code',
        'promo_discount_percentage',
        'status',
        'sync_status',
        'source',
        'payload',
        'error_message',
        'sync_error',           // ✅ Nouveau
        'shopify_order_id',     // ✅ Nouveau
        'shopify_order_number', // ✅ Nouveau
        'synced_at',
    ];

     protected $casts = [
        'payload' => 'array',
        'amount' => 'decimal:2',
        'quantity' => 'integer',
        'promo_discount_percentage' => 'decimal:2',
        'synced_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'sync_status' => self::SYNC_NOT_SYNCED,
        'currency' => 'EUR',
        'source' => 'manual',
    ];

    /**
     * Relation avec la boutique
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Relation avec les items (si vous utilisez une table séparée)
     * Commentez cette méthode si vous utilisez une colonne JSON
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Boot method pour logger les changements
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            Log::info('Order::creating event', [
                'customer_name' => $order->customer_name,
                'amount' => $order->amount,
                'items_count' => is_array($order->items) ? count($order->items) : 0
            ]);
        });

        static::created(function ($order) {
            Log::info('Order::created event', [
                'id' => $order->id,
                'customer_name' => $order->customer_name,
                'has_items' => !empty($order->items)
            ]);
        });

        static::updating(function ($order) {
            Log::info('Order::updating event', [
                'id' => $order->id,
                'changes' => $order->getDirty()
            ]);
        });

        static::saving(function (Order $order) {
            // Backfill des infos promo si elles sont présentes dans le payload
            $payloadDiscount = $order->payload['discount'] ?? [];
            $percentInPayload = $payloadDiscount['percent'] ?? null;
            $codeInPayload = $payloadDiscount['code'] ?? null;

            if ($order->promo_discount_percentage === null && $percentInPayload !== null) {
                $order->promo_discount_percentage = $percentInPayload;
            }

            if (empty($order->promo_code) && !empty($codeInPayload)) {
                $order->promo_code = $codeInPayload;
            }
        });
    }

    /**
     * Accesseur pour obtenir les items formatés
     */
    public function getFormattedItemsAttribute(): array
    {
        // Si vous utilisez une relation
        if ($this->relationLoaded('items')) {
            return $this->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'shopify_variant_id' => $item->shopify_variant_id,
                ];
            })->toArray();
        }

        // Si vous utilisez une colonne JSON
        return $this->items ?? [];
    }

    /**
     * Scope pour les commandes non synchronisées
     */
    public function scopeNotSynced($query)
    {
        return $query->where('sync_status', self::SYNC_NOT_SYNCED);
    }

    /**
     * Scope pour les commandes synchronisées
     */
    public function scopeSynced($query)
    {
        return $query->where('sync_status', self::SYNC_SYNCED);
    }

    /**
     * Scope pour les commandes en échec
     */
    public function scopeFailedSync($query)
    {
        return $query->where('sync_status', self::SYNC_FAILED);
    }
}
