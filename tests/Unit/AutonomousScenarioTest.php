<?php

namespace Tests\Unit;

use App\Models\AutonomousScenario;
use App\Models\Product;
use App\Models\Shop;
use App\Services\AutonomousOrderGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutonomousScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_should_run_now_respects_window(): void
    {
        $shop = Shop::factory()->create(['timezone' => 'UTC']);
        $scenario = AutonomousScenario::factory()->create([
            'shop_id' => $shop->id,
            'window_start_time' => '00:00:00',
            'window_end_time' => '23:59:59',
            'is_active' => true,
        ]);

        $generator = new AutonomousOrderGeneratorService();
        $this->assertTrue($generator->shouldRunNow($scenario));
    }

    public function test_generate_order_creates_order_and_item(): void
    {
        $shop = Shop::factory()->create(['timezone' => 'UTC']);
        $product = Product::factory()->create([
            'shop_id' => $shop->id,
            'shopify_product_id' => '1',
            'shopify_variant_id' => '1',
            'price' => 10,
        ]);
        $scenario = AutonomousScenario::factory()->create([
            'shop_id' => $shop->id,
            'is_active' => true,
            'window_start_time' => '00:00:00',
            'window_end_time' => '23:59:59',
        ]);
        $scenario->products()->attach($product);

        $generator = new AutonomousOrderGeneratorService();
        $order = $generator->generateOrder($scenario);

        $this->assertEquals($scenario->shop_id, $order->shop_id);
        $this->assertCount(1, $order->items);
    }
}
