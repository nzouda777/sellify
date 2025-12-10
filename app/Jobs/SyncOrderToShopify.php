<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncOrderToShopify implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $orderId)
    {
    }

    public function handle(OrderSyncService $orderSyncService): void
    {
        $order = Order::with('items', 'shop')->find($this->orderId);
        if (!$order) {
            return;
        }

        $orderSyncService->syncToShopify($order);
    }
}
