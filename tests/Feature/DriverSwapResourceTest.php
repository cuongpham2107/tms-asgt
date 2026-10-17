<?php

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Http\Resources\DriverSwapResource;
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('handover_km follows the linked checkpoint rather than the stale swap column', function () {
    $driver = User::factory()->create();
    $vehicle = Vehicle::create([
        'plate_number' => 'DSR-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);
    $trip = Trip::create([
        'trip_code' => 'TRIP-DSR',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::DriverSwap,
        'start_km' => 1000,
    ]);
    $checkpoint = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::DriverSwap->value,
        'occurred_at' => now(),
        'km_reading' => 1055,
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
    ]);
    $swap = DriverSwap::create([
        'trip_id' => $trip->id,
        'checkpoint_id' => $checkpoint->id,
        'from_driver_id' => $driver->id,
        'to_driver_id' => $driver->id,
        'handover_km' => 1050,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $driver->id,
    ]);

    expect((float) DriverSwapResource::make($swap->fresh())->resolve()['handover_km'])->toBe(1055.0);
});
