<?php

namespace App\Services;

use App\Jobs\GenerateAutonomousOrder;
use App\Models\AutonomousScenario;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
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
            $customer = $this->generateFakeCustomer();

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
                'customer_name' => $customer['name'],
                'customer_email' => $customer['email'],
                'customer_phone' => $customer['phone'],
                'amount' => $amount,
                'currency' => $scenario->currency ?? 'EUR',
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
                        'address1' => $customer['address'],
                        'city' => $customer['city'],
                        'zip' => $customer['zip'],
                        'country' => $customer['country'],
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

    protected function generateFakeCustomer(): array
    {
        $firstNames = ['John', 'Michael', 'Robert', 'Jessica', 'Thomas', 'Laura', 'Nicholas', 'Emily', 'Alexander', 'Chloe', 'Luke', 'Cassidy', 'Anthony', 'Julia', 'Hope', 'Leah', 'Maxwell', 'Makenna', 'Riley', 'Sarah'];
        $lastNames  = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Styles', 'Martinez', 'Scofield', 'Lopez', 'McTavish', 'Wilson', 'Anderson', 'Gallagher', 'Taylor', 'Moore', 'Jackson', 'Martin'];
        $cities     = ['New York', 'Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Philadelphia', 'San Antonio', 'San Diego', 'Dallas', 'San Jose', 'Austin', 'Jacksonville', 'San Francisco', 'Columbus', 'Fort Worth'];
        $countries  = ['United States', 'Canada', 'United Kingdom', 'Australia', 'Germany', 'France', 'Spain', 'Italy', 'Netherlands', 'Belgium'];
        $domains    = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'protonmail.com'];
        $streets    = ['Main Street', 'Elm Street', 'Oak Street', 'Pine Avenue', 'Maple Avenue', 'Willow Street', 'Park Avenue', '4th Avenue', 'Broadway', 'Church Street'];

        $firstName = $firstNames[array_rand($firstNames)];
        $lastName  = $lastNames[array_rand($lastNames)];
        $name      = $firstName . ' ' . $lastName;

        $email = strtolower($this->latinize($firstName) . '.' . $this->latinize($lastName) . mt_rand(1, 999)) . '@' . $domains[array_rand($domains)];

        $phonePrefix = mt_rand(0, 1) === 0 ? '+336' : '+337';
        $phone = $phonePrefix . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);

        $streetNumber = mt_rand(1, 150);
        $address = $streetNumber . ' ' . $streets[array_rand($streets)];

        $zip = str_pad((string) mt_rand(1000, 99999), 5, '0', STR_PAD_LEFT);

        return [
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'address' => $address,
            'city'    => $cities[array_rand($cities)],
            'zip'     => $zip,
            'country' => $countries[array_rand($countries)],
        ];
    }

    protected function latinize(string $text): string
    {
        $map = [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ý' => 'y',
            'ñ' => 'n',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'Ç' => 'C',
            'Î' => 'I', 'Ï' => 'I', 'Í' => 'I',
            'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Õ' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ú' => 'U',
            'Ÿ' => 'Y', 'Ý' => 'Y',
            'Ñ' => 'N',
        ];

        return strtr($text, $map);
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
