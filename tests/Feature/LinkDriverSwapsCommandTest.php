<?php

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $from = User::factory()->create();
    $to = User::factory()->create();

    $shift = fn (User $driver): DriverShift => DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 1000,
        'start_time' => now()->subHours(12),
    ]);
    $fromShift = $shift($from);
    $toShift = $shift($to);

    $vehicle = Vehicle::create([
        'plate_number' => 'LINK-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 1300,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-LINK',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $to->id,
        'shift_id' => $fromShift->id,
        'status' => TripStatus::Completed,
        'start_km' => 1000,
        'end_km' => 1300,
        'started_at' => now()->subHours(10),
    ]);

    $this->handoverCheckpoint = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'driver_id' => $from->id,
        'shift_id' => $fromShift->id,
        'checkpoint_type' => CheckpointType::DriverSwap,
        'km_reading' => 1050,
        'occurred_at' => now()->subHours(9),
    ]);

    $makeSwap = fn (float $km): DriverSwap => DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $from->id,
        'to_driver_id' => $to->id,
        'from_shift_id' => $fromShift->id,
        'to_shift_id' => $toShift->id,
        'handover_km' => $km,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $from->id,
    ]);

    $this->matchedSwap = $makeSwap(1050);
    $this->orphanSwap = $makeSwap(1300);
});

test('links legacy swaps to matching handover checkpoints and reports the rest', function () {
    $this->artisan('trips:link-driver-swaps')
        ->expectsOutputToContain('Đã gắn: 1')
        ->expectsOutputToContain('Không khớp: 1')
        ->assertSuccessful();

    expect($this->matchedSwap->fresh()->checkpoint_id)->toBe($this->handoverCheckpoint->id)
        ->and($this->orphanSwap->fresh()->checkpoint_id)->toBeNull();
});

test('dry run changes nothing', function () {
    $this->artisan('trips:link-driver-swaps --dry-run')
        ->expectsOutputToContain('Đã gắn: 1')
        ->assertSuccessful();

    expect($this->matchedSwap->fresh()->checkpoint_id)->toBeNull();
});
