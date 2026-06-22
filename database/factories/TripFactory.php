<?php

namespace Database\Factories;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class TripFactory extends Factory
{
    protected $model = Trip::class;

    public function definition(): array
    {
        return [
            'trip_code' => $this->faker->unique()->word().'-'.$this->faker->unique()->randomNumber(4),
            'vehicle_id' => Vehicle::factory(),
            'driver_id' => User::factory(),
            'status' => TripStatus::Pending,
        ];
    }
}
