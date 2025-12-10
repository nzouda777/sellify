<?php

namespace Database\Factories;

use App\Models\AutonomousScenario;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutonomousScenario>
 */
class AutonomousScenarioFactory extends Factory
{
    protected $model = AutonomousScenario::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'name' => $this->faker->sentence(3),
            'location_label' => $this->faker->country(),
            'faker_locale' => 'en_US',
            'window_start_time' => '00:00:00',
            'window_end_time' => '23:59:59',
            'min_interval_seconds' => 60,
            'max_interval_seconds' => 120,
            'min_quantity' => 1,
            'max_quantity' => 3,
            'is_active' => true,
        ];
    }
}
