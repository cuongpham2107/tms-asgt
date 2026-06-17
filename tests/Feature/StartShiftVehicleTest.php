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

test('driver without vehicle starting shift with vehicle_id succeeds and assigns vehicle', function () {
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

    $vehicle->refresh();
    expect($vehicle->current_driver_id)->toBe($driver->id);

    // Verify shift vehicles segment was created
    $shift = $driver->driverShifts()->whereNull('end_time')->first();
    expect($shift)->not->toBeNull();
    expect($shift->shiftVehicles()->count())->toBe(1);
    expect($shift->shiftVehicles()->first()->vehicle_id)->toBe($vehicle->id);
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

    $vehicle->refresh();
    expect($vehicle->current_driver_id)->toBe($driver->id);

    $shift = $driver->driverShifts()->whereNull('end_time')->first();
    expect($shift)->not->toBeNull();
    expect($shift->shiftVehicles()->count())->toBe(1);
    expect($shift->shiftVehicles()->first()->vehicle_id)->toBe($vehicle->id);
});
