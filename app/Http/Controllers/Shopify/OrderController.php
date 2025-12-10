<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderSyncService;
use Illuminate\Http\RedirectResponse;

class OrderController extends Controller
{
    public function __construct(private OrderSyncService $orderSyncService)
    {
    }

    public function sync(Order $order): RedirectResponse
    {
        $this->orderSyncService->syncToShopify($order);

        return back()->with('success', 'Order sync triggered');
    }
}
