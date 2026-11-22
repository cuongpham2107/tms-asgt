<?php

namespace Database\Factories;

use App\Models\Vehicle;
use App\Models\VehicleKmCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleKmCycle>
 */
class VehicleKmCycleFactory extends Factory
{
    public function definition(): array
    {
        $startKm = $this->faker->numberBetween(10_000, 400_000);
        $kmRun = $this->faker->numberBetween(500, 3_500);
        $overKm = max(0, $kmRun - 3000);

        return [
            'vehicle_id' => Vehicle::factory(),
            'cycle_start' => '2026-10-23 12:00:00',
            'cycle_end' => '2026-11-23 12:00:00',
            'start_km' => $startKm,
            'end_km' => $startKm + $kmRun,
            'km_run' => $kmRun,
            'tracks_km_over' => true,
            'km_limit' => 3000,
            'over_km' => $overKm,
            'over_fee_per_km' => 10000,
            'over_fee' => $overKm * 10000,
            'vehicle_owner' => 'HMA',
        ];
    }
}
