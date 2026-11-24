<?php

use App\Enums\ShiftType;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);
});

test('driver without vehicle starting shift without vehicle_id succeeds', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $response = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
    ]);

    $response->assertSuccessful();

    $shift = $driver->driverShifts()->whereNull('end_time')->first();
    expect($shift)->not->toBeNull();
    expect($shift->start_time)->not->toBeNull();
});

test('driver without vehicle starting shift with vehicle_id succeeds', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-777.77',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $response = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $vehicle->id,
    ]);

    $response->assertSuccessful();

    // current_driver_id is intentionally not modified by the controller
    // Vehicle tracking is done via Trip.vehicle_id
    $shift = $driver->driverShifts()->whereNull('end_time')->first();
    expect($shift)->not->toBeNull()
        ->and($shift->vehicle_id)->toBe($vehicle->id)
        ->and($vehicle->fresh()->current_driver_id)->toBeNull();

    $response->assertJsonPath('shift.selected_vehicle_id', $vehicle->id)
        ->assertJsonPath('shift.selected_vehicle.plate_number', '51C-777.77');
});

test('shift-options returns active company vehicles and suggested vehicle for driver', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $assignedVehicle = Vehicle::create([
        'plate_number' => '29H-111.11',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'even_driver_id' => $driver->id,
    ]);

    $otherCompanyVehicle = Vehicle::create([
        'plate_number' => '29H-222.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    Vehicle::create([
        'plate_number' => '29H-RENT',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'Thuê ngoài',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Rent,
    ]);

    Vehicle::create([
        'plate_number' => '29H-OFF',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => false,
        'status' => VehicleStatus::Off,
        'type' => VehicleOwnerType::Company,
    ]);

    $response = $this->getJson('/api/driver/vehicles/shift-options')
        ->assertSuccessful()
        ->assertJsonPath('suggested_vehicle_id', $assignedVehicle->id);

    $returnedIds = collect($response->json('data'))->pluck('id')->all();
    expect($returnedIds)->toEqualCanonicalizing([$assignedVehicle->id, $otherCompanyVehicle->id]);
});

test('driver with vehicle starting shift without vehicle_id succeeds using current vehicle', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-888.88',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
    ]);

    $response = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
    ]);

    $response->assertSuccessful();

    // current_driver_id is intentionally not modified by the controller
    $shift = $driver->driverShifts()->whereNull('end_time')->first();
    expect($shift)->not->toBeNull();
});

test('starting a shift links pending driver swaps to the new shift', function () {
    $receiver = User::factory()->create();
    $receiver->assignRole($this->driverRole);
    $giver = User::factory()->create();
    $vehicle = Vehicle::create([
        'plate_number' => '51C-888.88',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);
    $trip = Trip::create(['trip_code' => 'TRIP-SWAP-KM', 'vehicle_id' => $vehicle->id, 'driver_id' => $giver->id]);
    $checkpoint = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => 'driver_swap',
        'occurred_at' => now(),
        'km_reading' => 1050,
        'driver_id' => $giver->id,
        'vehicle_id' => $vehicle->id,
    ]);
    $swap = DriverSwap::create([
        'trip_id' => $trip->id,
        'checkpoint_id' => $checkpoint->id,
        'from_driver_id' => $giver->id,
        'to_driver_id' => $receiver->id,
        'handover_km' => 1040,
        'reason' => 'shift_handover',
        'created_by' => $giver->id,
    ]);
    Sanctum::actingAs($receiver);

    $this->postJson('/api/driver/shifts/start', ['shift_type' => ShiftType::Full->value])->assertSuccessful();

    $shift = $receiver->driverShifts()->first();
    expect($swap->fresh()->to_shift_id)->toBe($shift->id);
    // Km bàn giao vẫn nằm ở checkpoint của chuyến, không lưu trên ca
    expect((float) $swap->fresh()->handover_km)->toBe(1040.0);
});
