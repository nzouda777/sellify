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
            'promo_code' => ['nullable', 'string', 'max:255'],
            'promo_discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $order = DB::transaction(function () use ($data) {
            $totalAmount = 0;
            $discountAmount = 0;
            $discountPercent = isset($data['promo_discount_percentage'])
                ? max(0, min(100, (float) $data['promo_discount_percentage']))
                : 0;

            $order = Order::create([
                'shop_id' => $data['shop_id'],
                'customer_name' => $data['name'],
                'customer_email' => $data['email'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'status' => Order::STATUS_PENDING,
                'sync_status' => Order::SYNC_NOT_SYNCED,
                'source' => 'api',
                'promo_code' => $data['promo_code'] ?? null,
                'promo_discount_percentage' => $discountPercent,
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

            if (!empty($data['promo_code']) && $discountPercent > 0) {
                $discountAmount = round($totalAmount * ($discountPercent / 100), 2);
                $totalAmount = max($totalAmount - $discountAmount, 0);
            }

            $order->update([
                'amount' => round($totalAmount, 2),
                'quantity' => collect($data['items'])->sum('quantity'),
                'payload' => [
                    'discount' => [
                        'code' => $data['promo_code'] ?? null,
                        'percent' => $discountPercent,
                        'amount' => $discountAmount,
                    ],
                ],
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
