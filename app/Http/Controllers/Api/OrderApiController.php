<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\OrderSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class OrderApiController extends Controller
{
    public function __construct(private OrderSyncService $orderSyncService)
    {
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shop_id' => ['required', 'exists:shops,id'],
            'name' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'currency' => ['nullable', 'string', 'size:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $order = DB::transaction(function () use ($data) {
            $totalAmount = 0;

            $order = Order::create([
                'shop_id' => $data['shop_id'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'status' => Order::STATUS_PENDING,
                'sync_status' => Order::SYNC_NOT_SYNCED,
                'source' => 'api',
            ]);

            foreach ($data['items'] as $itemData) {
                $product = Product::find($itemData['product_id']);
                $quantity = $itemData['quantity'];
                $lineTotal = ($product->price ?? 0) * $quantity;
                $totalAmount += $lineTotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'shopify_variant_id' => $product->shopify_variant_id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'total_price' => $lineTotal,
                ]);
            }

            $order->update([
                'amount' => $totalAmount,
                'quantity' => collect($data['items'])->sum('quantity'),
            ]);

            return $order;
        });

        try {
            // Synchroniser la commande avec Shopify
            $this->orderSyncService->syncToShopify($order->load('items'));
            Log::info('Commande créée et synchronisée avec succès', ['order_id' => $order->id]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la synchronisation de la commande avec Shopify', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return response()->json($order->fresh()->load('items'), Response::HTTP_CREATED);
    }
}
