<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderSyncService
{
    public function syncToShopify(Order $order): void
    {
        try {
            Log::info('=== DEBUT syncToShopify ===', [
                'order_id' => $order->id,
                'shop_id' => $order->shop_id
            ]);

            // Vérifier que la boutique existe
            if (!$order->shop) {
                throw new \Exception('Shop not found for this order');
            }

            $shop = $order->shop;

            // Vérifier les credentials Shopify
            if (empty($shop->shopify_domain) || empty($shop->access_token)) {
                Log::error('Shopify credentials missing for shop: ' . $shop->name, [
                    'shop_id' => $shop->id,
                    'shop_name' => $shop->name,
                    'shopify_domain' => $shop->shopify_domain,
                    'access_token' => $shop->access_token
                ]);
                throw new \Exception('Shopify credentials missing for shop: ' . $shop->name);
            }

            // Récupérer les items de la commande
            $items = $this->getOrderItems($order);

            Log::info('Items récupérés pour sync', [
                'order_id' => $order->id,
                'items_count' => count($items),
                'items_raw' => $items
            ]);

            if (empty($items)) {
                throw new \Exception('No items found for this order. Cannot sync to Shopify.');
            }

            $discount = $this->calculateDiscountContext($order, $items);

            // Construire les line_items pour Shopify
            $lineItems = [];
            foreach ($items as $item) {
                $lineItem = [
                    'quantity' => (int) $item['quantity'],
                    'price' => (string) number_format((float) $item['unit_price'], 2, '.', ''),
                ];

                // Ajouter variant_id si disponible
                if (!empty($item['shopify_variant_id'])) {
                    $lineItem['variant_id'] = (string) $item['shopify_variant_id'];
                } else {
                    // Si pas de variant_id, on doit avoir au moins un titre
                    $product = Product::find($item['product_id']);
                    $lineItem['title'] = $product?->title ?? $product?->name ?? 'Product';
                    $lineItem['sku'] = $product?->sku ?? '';
                }
                
                $lineItems[] = $lineItem;
            }

            Log::info('Line items construits', [
                'order_id' => $order->id,
                'line_items' => $lineItems
            ]);

            // Préparer les informations client
            $customerNames = $this->splitCustomerName($order->customer_name);
            
            $payload = $order->payload ?? [];
            $shippingAddress = $payload['shipping_address'] ?? [];

            // Construire le payload Shopify
            $shopifyPayload = [
                'order' => [
                    'line_items' => $lineItems,
                    'customer' => [
                        'first_name' => $customerNames['first_name'],
                        'last_name' => $customerNames['last_name'],
                        'email' => $order->customer_email,
                        'phone' => $order->customer_phone,
                    ],
                    // 'financial_status' => $this->mapStatus($order->status),
                    'currency' => $order->currency ?? 'EUR',
                    'shipping_address' => [
                        'first_name' => $customerNames['first_name'],
                        'last_name' => $customerNames['last_name'],
                        'address1' => $shippingAddress['address1'] ?? '',
                        'city' => $shippingAddress['city'] ?? '',
                        'province' => $shippingAddress['province'] ?? null,
                        'zip' => $shippingAddress['zip'] ?? '',
                        'country' => $shippingAddress['country'] ?? 'France',
                        'phone' => $order->customer_phone,
                    ],
                    'email' => $order->customer_email,
                    'send_receipt' => false,
                    'send_fulfillment_receipt' => false,
                    // 'tags' => 'created_from_filament',
                    // 'note' => 'Order created from Filament admin panel',
                    // marque la commande comme paye
                    'financial_status' => 'paid',
                    'discount_codes' => $this->formatDiscountCodes($discount),
                    // 'fulfillment_status' => 'fulfilled',

                    // 'source_name' => "web",          // 👈 très important
                    'browser_ip' => "127.0.0.1",       // tu peux mettre l'IP du client si tu l'as
                    'user_agent' => "Mozilla/5.0",   // ou un UA générique
                    'landing_site' => "https://firstmillionever.myshopify.com/products/xxx",
                    'referring_site' => "https://firstmillionever.myshopify.com",
                    'status' => 'paid',
                    'transactions' => [
                        [
                            'amount' => $order->amount,
                            'currency' => $order->currency ?? 'EUR',
                            'kind' => 'sale',
                            'status' => 'success',
                            'source' => 'POS',
                        ],
                    ],

                    // defini la commande comme provenant de online store
                //    'source_name' => 'web',
                ],
            ];

            Log::info('Payload Shopify final', [
                'order_id' => $order->id,
                'payload' => $shopifyPayload
            ]);

            // Marquer comme en cours de synchronisation
            $order->update(['sync_status' => 'syncing']);

            // Envoyer à Shopify
            $endpoint = "https://{$shop->shopify_domain}/admin/api/2025-10/orders.json";
            
            Log::info('Envoi vers Shopify', [
                'order_id' => $order->id,
                'endpoint' => $endpoint
            ]);

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $shop->access_token,
                'Content-Type' => 'application/json',
            ])->post($endpoint, $shopifyPayload);

            Log::info('Réponse Shopify', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            if (!$response->successful()) {
                Log::error('Shopify order creation failed', [
                    'order_id' => $order->id,
                    'shop_id' => $shop->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'endpoint' => $endpoint,
                    'payload' => $shopifyPayload
                ]);

                $order->update([
                    'sync_status' => 'failed',
                    'sync_error' => $response->body()
                ]);

                throw new \Exception('Shopify order creation failed: ' . $response->body());
            }

            $shopifyOrder = $response->json('order');

            // Mettre à jour la commande locale
            $order->update([
                'shopify_order_id' => $shopifyOrder['id'] ?? null,
                'shopify_order_number' => $shopifyOrder['order_number'] ?? null,
                'sync_status' => 'synced',
                'synced_at' => now(),
                'sync_error' => null,
            ]);

            Log::info('=== FIN syncToShopify (SUCCESS) ===', [
                'order_id' => $order->id,
                'shopify_order_id' => $shopifyOrder['id'] ?? null
            ]);

        } catch (\Throwable $e) {
            Log::error('Order sync failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
                'shop_id' => $order->shop_id ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            $order->update([
                'sync_status' => 'failed',
                'sync_error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Récupère les items de la commande
     * Gère à la fois les relations et les colonnes JSON
     */
    protected function getOrderItems(Order $order): array
    {
        // Essayer d'abord la relation
        if ($order->relationLoaded('items') || method_exists($order, 'items')) {
            try {
                $relationItems = $order->items()->get();
                if ($relationItems->isNotEmpty()) {
                    Log::info('Items récupérés via relation', [
                        'order_id' => $order->id,
                        'count' => $relationItems->count()
                    ]);
                    return $relationItems->toArray();
                }
            } catch (\Throwable $e) {
                Log::warning('Relation items non disponible', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Sinon, essayer la colonne JSON
        if (isset($order->items) && is_array($order->items)) {
            Log::info('Items récupérés via colonne JSON', [
                'order_id' => $order->id,
                'count' => count($order->items)
            ]);
            return $order->items;
        }

        // Sinon, essayer dans le payload
        if (isset($order->payload['items']) && is_array($order->payload['items'])) {
            Log::info('Items récupérés via payload', [
                'order_id' => $order->id,
                'count' => count($order->payload['items'])
            ]);
            return $order->payload['items'];
        }

        Log::error('Aucun item trouvé pour la commande', [
            'order_id' => $order->id,
            'items_attribute' => $order->items ?? null,
            'payload' => $order->payload ?? null
        ]);

        return [];
    }

    /**
     * Sépare le nom complet en prénom et nom
     */
    protected function splitCustomerName(?string $fullName): array
    {
        if (empty($fullName)) {
            return ['first_name' => '', 'last_name' => ''];
        }

        $parts = explode(' ', trim($fullName), 2);
        
        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * Mappe le statut local vers le statut Shopify
     */
    protected function mapStatus(?string $status): string
    {
        return match($status) {
            'completed' => 'paid',
            'processing' => 'pending',
            'cancelled' => 'voided',
            default => 'pending',
        };
    }

    /**
     * Calcule le contexte de remise à partir de la commande et des articles
     */
    protected function calculateDiscountContext(Order $order, array $items): array
    {
        $payloadDiscount = $order->payload['discount'] ?? [];
        $percent = $payloadDiscount['percent'] ?? $order->promo_discount_percentage ?? 0;
        $percent = max(0, min(100, (float) $percent));
        $code = $payloadDiscount['code'] ?? $order->promo_code ?? null;

        if (empty($code) || $percent <= 0) {
            return [
                'code' => $code,
                'percent' => 0.0,
                'amount' => 0.0,
            ];
        }

        $amount = isset($payloadDiscount['amount'])
            ? max(0, (float) $payloadDiscount['amount'])
            : null;

        if ($amount === null) {
            $subtotal = $this->calculateSubtotalFromItems($items);
            $amount = round($subtotal * ($percent / 100), 2);
        }

        return [
            'code' => $code,
            'percent' => $percent,
            'amount' => $amount,
        ];
    }

    /**
     * Construit le tableau attendu par Shopify pour les discount_codes
     */
    protected function formatDiscountCodes(array $discount): array
    {
        if (empty($discount['code'])) {
            return [];
        }

        $discountCode = [
            'code' => $discount['code'],
        ];

        if (($discount['percent'] ?? 0) > 0) {
            $discountCode['amount'] = number_format((float) $discount['percent'], 2, '.', '');
            $discountCode['type'] = 'percentage';
        }

        return [$discountCode];
    }

    /**
     * Calcule le sous-total à partir des items (unit_price x quantity)
     */
    protected function calculateSubtotalFromItems(array $items): float
    {
        $subtotal = 0;

        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $subtotal += $qty * $unitPrice;
        }

        return round($subtotal, 2);
    }
}
