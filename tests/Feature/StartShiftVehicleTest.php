<?php

use App\Enums\ShiftType;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
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

test('driver without vehicle starting shift without vehicle_id returns 422', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $response = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Vui lòng chọn phương tiện để bắt đầu ca.');
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
    expect($shift)->not->toBeNull();
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
