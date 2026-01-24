<?php

namespace App\Services;

use App\Jobs\GenerateAutonomousOrder;
use App\Models\AutonomousScenario;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Faker\Factory as FakerFactory;
use Illuminate\Support\Facades\Log;


class AutonomousOrderGeneratorService
{
    public function shouldRunNow(AutonomousScenario $scenario, Carbon $now = null): bool
    {
        $now = $now ?: Carbon::now($scenario->shop->timezone ?? 'UTC');
        $start = Carbon::parse($scenario->window_start_time, $now->timezone);
        $end = Carbon::parse($scenario->window_end_time, $now->timezone);

        if ($end->lessThan($start)) {
            $end->addDay(); // overnight windows
        }

        return $now->between($start, $end);
    }

    public function scheduleNextRun(AutonomousScenario $scenario, Carbon $from = null): void
    {
        $from = $from ?: Carbon::now($scenario->shop->timezone ?? 'UTC');
        $interval = random_int($scenario->min_interval_seconds, $scenario->max_interval_seconds);
        $nextRun = $from->copy()->addSeconds($interval);

        Log::info('AutonomousScenario: schedule next run', [
            'scenario_id' => $scenario->id,
            'from' => $from->toDateTimeString(),
            'interval_seconds' => $interval,
            'next_run_at' => $nextRun->toDateTimeString(),
        ]);

        $scenario->update(['next_run_at' => $nextRun]);

        // Planifie le prochain job GenerateAutonomousOrder avec un delay respectant la plage min/max
        GenerateAutonomousOrder::dispatch($scenario->id)->delay($interval);

        Log::info('AutonomousScenario: end scheduleNextRun', [
            'scenario_id' => $scenario->id,
            'next_run_at' => $nextRun->toDateTimeString(),
            'dispatched_with_delay_seconds' => $interval,
        ]);
    }

    public function generateOrder(AutonomousScenario $scenario): Order
    {
        Log::info('AutonomousScenario: start generateOrder', [
            'scenario_id' => $scenario->id,
            'shop_id' => $scenario->shop_id,
            'generated_orders' => $scenario->generated_orders,
            'target_orders' => $scenario->target_orders,
        ]);

        try {
            $fakerLocale = $scenario->faker_locale ?? $this->mapLocaleFromLocation($scenario->location_label);

            // Crée un générateur Faker spécifique à la locale du scénario
            /** @var \Faker\Generator $faker */
            $faker = FakerFactory::create($fakerLocale);

            $product = $this->pickProduct($scenario);
            $quantity = random_int($scenario->min_quantity, $scenario->max_quantity);
            $unitPrice = $product->price ?? 0;

            $amount = $scenario->min_amount && $scenario->max_amount
                ? $this->randomFloatInRange($scenario->min_amount, $scenario->max_amount)
                : $unitPrice * $quantity;

            $discountAmount = 0;
            $discountPercent = (float) ($scenario->promo_discount_percentage ?? 0);

            if (!empty($scenario->promo_code) && $discountPercent > 0) {
                $discountAmount = round($amount * ($discountPercent / 100), 2);
                $amount = max($amount - $discountAmount, 0);
            }

            Log::info('AutonomousScenario: picked product and computed amounts', [
                'scenario_id' => $scenario->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $amount,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
            ]);

            $order = Order::create([
                'shop_id' => $scenario->shop_id,
                'customer_name' => $faker->name(),
                'customer_email' => $faker->safeEmail(),
                'customer_phone' => str_replace(['-', ' ', '(', ')', '+', '.'], '', $faker->phoneNumber()),
                'amount' => $amount,
                'currency' => $scenario->shop->products()->first()?->payload['presentment_prices'][0]['price']['currency_code'] ?? 'EUR',
                'quantity' => $quantity,
                // randomly set orders as fulfill based on the number of items that needs to be fulfill
                'fulfill_orders' => $scenario->fulfill_orders > 0 ? true : false ,

                'promo_code' => $scenario->promo_code,
                'promo_discount_percentage' => $scenario->promo_discount_percentage,
                'status' => Order::STATUS_PENDING,
                'sync_status' => Order::SYNC_NOT_SYNCED,
                'source' => 'autonomous',
                'payload' => [
                    'shipping_address' => [
                        'address1' => $faker->streetAddress(),
                        'city' => $faker->city(),
                        'zip' => $faker->postcode(),
                        'country' => $faker->country(),
                    ],
                    'discount' => [
                        'code' => $scenario->promo_code,
                        'percent' => $discountPercent,
                        'amount' => $discountAmount,
                    ],
                ],
            ]);

            Log::info('AutonomousScenario: order created', [
                'scenario_id' => $scenario->id,
                'order_id' => $order->id,
            ]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'shopify_variant_id' => $product->shopify_variant_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $unitPrice * $quantity,
            ]);

            Log::info('AutonomousScenario: order item created', [
                'scenario_id' => $scenario->id,
                'order_id' => $order->id,
                'product_id' => $product->id,
            ]);

            // Incrémenter le compteur de commandes générées
            $scenario->increment('generated_orders');

            // decerementer le compteur de commande livre
            $scenario->decrement('fulfill_orders');
            $scenario->refresh();


            Log::info('AutonomousScenario: increment generated_orders', [
                'scenario_id' => $scenario->id,
                'generated_orders' => $scenario->generated_orders,
                'target_orders' => $scenario->target_orders,
            ]);

            // Si le quota est atteint, désactiver définitivement le scénario (one-shot)
            if (!is_null($scenario->target_orders) && $scenario->generated_orders >= $scenario->target_orders) {
                Log::info('AutonomousScenario: target reached, disabling scenario', [
                    'scenario_id' => $scenario->id,
                ]);

                $scenario->update([
                    'is_active' => false,
                    'next_run_at' => null,
                ]);
            } else {
                // Sinon, planifier la prochaine exécution avec un intervalle aléatoire
                $this->scheduleNextRun($scenario);
            }

            Log::info('AutonomousScenario: end generateOrder', [
                'scenario_id' => $scenario->id,
                'order_id' => $order->id,
            ]);

            return $order->fresh('items');

        } catch (\Throwable $e) {
            Log::error('AutonomousScenario: error during generateOrder', [
                'scenario_id' => $scenario->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            
            throw $e;
        }
    }

    protected function pickProduct(AutonomousScenario $scenario)
    {
        $products = $scenario->products;
        if ($products->isEmpty()) {
            throw new \RuntimeException('No products attached to scenario '.$scenario->id);
        }

        $pool = [];
        foreach ($products as $product) {
            $weight = $product->pivot->weight ?? 1;
            $pool = array_merge($pool, array_fill(0, $weight, $product->id));
        }

        $productId = $pool[array_rand($pool)];

        return $products->firstWhere('id', $productId);
    }

    protected function randomFloatInRange(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    protected function mapLocaleFromLocation(?string $location): string
    {
        if (!$location) {
            return config('app.faker_locale', 'en_US');
        }

        $key = strtolower($location);
        return config('autonomous.location_locale_map')[$key] ?? config('app.faker_locale', 'en_US');
    }
}
