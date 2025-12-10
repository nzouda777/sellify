<?php

namespace App\Services\Shopify;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class WebhookHandlerService
{
    public function verify(string $payload, string $hmacHeader): bool
    {
        $calculated = base64_encode(hash_hmac('sha256', $payload, Config::get('shopify.webhook_secret'), true));

        return hash_equals($hmacHeader, $calculated);
    }

    public function handleProductWebhook(array $payload, string $shopDomain): void
    {
        $shop = Shop::where('shopify_domain', $shopDomain)->first();

        if (!$shop) {
            return;
        }

        foreach ($payload['variants'] ?? [] as $variant) {
            Product::updateOrCreate(
                [
                    'shop_id' => $shop->id,
                    'shopify_variant_id' => (string) $variant['id'],
                ],
                [
                    'shopify_product_id' => (string) $payload['id'],
                    'title' => $payload['title'] ?? 'Product',
                    'variant_title' => $variant['title'] ?? null,
                    'sku' => $variant['sku'] ?? null,
                    'price' => $variant['price'] ?? null,
                    'status' => $payload['status'] ?? 'active',
                    'payload' => $variant,
                ]
            );
        }
    }

    public function handleProductDeleted(array $payload): void
    {
        $variantIds = collect($payload['variants'] ?? [])->pluck('id')->map(fn ($id) => (string) $id);
        if ($variantIds->isEmpty()) {
            return;
        }

        Product::whereIn('shopify_variant_id', $variantIds)->delete();
    }

    public function handleOrderWebhook(array $payload): void
    {
        $externalId = $payload['id'] ?? null;

        if (!$externalId) {
            return;
        }

        $order = Order::where('external_order_id', $externalId)->first();
        if (!$order) {
            return;
        }

        $fulfillmentStatus = $payload['fulfillment_status'] ?? null;
        $order->update([
            'status' => $this->mapShopifyOrderStatus($payload['financial_status'] ?? null, $fulfillmentStatus),
            'payload' => $payload,
        ]);
    }

    protected function mapShopifyOrderStatus(?string $financial, ?string $fulfillment): string
    {
        return match (true) {
            $fulfillment === 'fulfilled' => Order::STATUS_DELIVERED,
            $financial === 'refunded' => Order::STATUS_CANCELLED,
            default => Order::STATUS_SYNCED,
        };
    }
}
