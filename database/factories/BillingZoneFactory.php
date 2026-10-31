<?php

namespace Database\Factories;

use App\Models\BillingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingZone>
 */
class BillingZoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Kcn '.fake()->unique()->city();

        return [
            'code' => $name,
            'name' => $name,
        ];
    }
}
