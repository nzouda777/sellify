<?php

namespace App\Services\Shopify;

use App\Models\Order;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyOrderService
{
    public function createShopifyOrder(Order $order): array
    {
        $shop = $order->shop;
        $version = Config::get('shopify.api_version');
        $endpoint = "https://{$shop->shopify_domain}/admin/api/{$version}/orders.json";

        $lineItems = $order->items->map(function ($item) {
            return [
                'variant_id' => $item->shopify_variant_id,
                'quantity' => $item->quantity,
                'price' => $item->unit_price,
            ];
        })->all();

        $payload = [
            'order' => [
                'line_items' => $lineItems,
                'customer' => [
                    'first_name' => $this->firstName($order->name),
                    'last_name' => $this->lastName($order->name),
                    'email' => $order->email,
                ],
                'financial_status' => 'pending',
                'currency' => $order->currency ?? 'USD',
            ],
        ];

        $shippingAddress = data_get($order->payload, 'shipping_address', []);
        if (!empty($shippingAddress)) {
            $payload['order']['shipping_address'] = [
                'address1' => $shippingAddress['address1'] ?? null,
                'city' => $shippingAddress['city'] ?? null,
                'province' => $shippingAddress['province'] ?? null,
                'zip' => $shippingAddress['zip'] ?? null,
                'country' => $shippingAddress['country'] ?? null,
            ];
        }

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type' => 'application/json',
        ])->post($endpoint, $payload);

        if ($response->failed()) {
            Log::error('Shopify order creation failed', [
                'order_id' => $order->id,
                'shop_id' => $shop->id,
                'status' => $response->status(),
                'body' => $response->body(),
                'endpoint' => $endpoint,
                'payload' => $payload,
            ]);
            $body = $response->body();
            throw new RuntimeException('Shopify order creation failed: '.$body);
        }

        Log::info('Shopify order created', [
            'order_id' => $order->id,
            'shop_id' => $shop->id,
            'status' => $response->status(),
            'shopify_order_id' => data_get($response->json(), 'order.id'),
        ]);

        return $response->json('order');
    }

    protected function firstName(?string $fullName): ?string
    {
        if (!$fullName) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($fullName), 2);
        return $parts[0] ?? null;
    }

    protected function lastName(?string $fullName): ?string
    {
        if (!$fullName) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($fullName), 2);
        return $parts[1] ?? null;
    }
}
