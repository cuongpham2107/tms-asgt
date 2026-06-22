<?php

namespace Database\Factories;

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'plate_number' => $this->faker->unique()->regexify('[0-9]{2}[A-Z]-[0-9]{3}\.[0-9]{2}'),
            'vehicle_type' => VehicleType::Normal,
            'owner' => 'ASGT',
            'is_active' => true,
            'status' => VehicleStatus::On,
            'type' => VehicleOwnerType::Company,
        ];
    }
}
