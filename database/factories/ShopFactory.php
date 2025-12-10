<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    protected $model = Shop::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'shopify_domain' => $this->faker->domainWord().'.myshopify.com',
            'access_token' => 'test-token',
            'timezone' => 'UTC',
            'status' => 'connected',
        ];
    }
}
