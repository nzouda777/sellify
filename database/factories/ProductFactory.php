<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'shopify_product_id' => $this->faker->uuid(),
            'shopify_variant_id' => $this->faker->uuid(),
            'title' => $this->faker->words(3, true),
            'variant_title' => $this->faker->word(),
            'sku' => $this->faker->ean8(),
            'price' => $this->faker->randomFloat(2, 10, 100),
            'status' => 'active',
        ];
    }
}
