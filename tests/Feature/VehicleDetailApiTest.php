<?php

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

test('guest cannot access vehicle detail api', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '51C-111.11',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $response = $this->getJson("/api/driver/vehicles/{$vehicle->id}");

    $response->assertStatus(401);
});

test('driver can fetch vehicle details', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-222.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'notes' => 'Some test notes',
    ]);

    $response = $this->getJson("/api/driver/vehicles/{$vehicle->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.plate_number', '51C-222.22')
        ->assertJsonPath('data.notes', 'Some test notes')
        ->assertJsonPath('data.status', 'on');
});

test('returns 404 for non-existent vehicle', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);
    Sanctum::actingAs($driver);

    $response = $this->getJson('/api/driver/vehicles/999999');

    $response->assertStatus(404);
});
