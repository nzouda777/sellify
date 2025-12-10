<?php

namespace App\Console\Commands;

use App\Jobs\SyncOrderToShopify;
use App\Models\Order;
use Illuminate\Console\Command;

class SyncQueuedOrdersCommand extends Command
{
    protected $signature = 'orders:sync-queued {--limit=50}';
    protected $description = 'Dispatch jobs to sync queued orders to Shopify during allowed windows';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $orders = Order::with('shop.syncWindows')
            ->where('status', Order::STATUS_PENDING)
            ->where('sync_status', Order::SYNC_NOT_SYNCED)
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            if ($order->shop?->isWithinSyncWindow()) {
                SyncOrderToShopify::dispatch($order->id);
            }
        }

        $this->info("Dispatched {$orders->count()} orders");

        return self::SUCCESS;
    }
}
