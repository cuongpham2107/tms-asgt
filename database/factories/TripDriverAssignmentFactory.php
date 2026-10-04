<?php

namespace Database\Factories;

use App\Models\Trip;
use App\Models\TripDriverAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TripDriverAssignment>
 */
class TripDriverAssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trip_id' => Trip::factory(),
            'driver_id' => User::factory(),
            'started_at' => now(),
        ];
    }

    public function ended(): static
    {
        return $this->state(fn () => ['ended_at' => now()]);
    }
}
