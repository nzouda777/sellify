<?php

namespace App\Services\Shopify;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyProductService
{
    public function syncProducts(Shop $shop): Collection
    {
        $version = Config::get('shopify.api_version');
        $sinceId = null;
        $imported = 0;

        do {
            $query = http_build_query(array_filter([
                'limit' => 250,
                'since_id' => $sinceId,
            ]));

            $endpoint = "https://{$shop->shopify_domain}/admin/api/{$version}/products.json?{$query}";

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $shop->access_token,
            ])->get($endpoint);

            if ($response->failed()) {
                Log::error('Shopify product sync failed', [
                    'shop_id' => $shop->id,
                    'domain' => $shop->shopify_domain,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return collect();
            }

            $batch = collect($response->json('products'));

            $batch->each(function ($shopifyProduct) use ($shop, &$imported, &$sinceId) {
                $sinceId = $shopifyProduct['id'];

                foreach ($shopifyProduct['variants'] as $variant) {
                    Product::updateOrCreate(
                        [
                            'shop_id' => $shop->id,
                            'shopify_variant_id' => (string) $variant['id'],
                        ],
                        [
                            'shopify_product_id' => (string) $shopifyProduct['id'],
                            'title' => $shopifyProduct['title'],
                            'variant_title' => $variant['title'] ?? null,
                            'sku' => $variant['sku'] ?? null,
                            'price' => $variant['price'] ?? null,
                            'status' => $shopifyProduct['status'] ?? 'active',
                            'payload' => $variant,
                        ]
                    );
                    $imported++;
                }
            });

        } while ($batch->isNotEmpty() && $batch->count() === 250);

        Log::info('Shopify product sync success', [
            'shop_id' => $shop->id,
            'domain' => $shop->shopify_domain,
            'imported_variants' => $imported,
            'products_count' => $shop->products()->count(),
        ]);

        return $shop->products()->get();
    }
}
