<?php

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user resource returns current vehicle when driver is assigned to a vehicle', function () {
    $driver = User::factory()->create();

    Vehicle::create([
        'plate_number' => '51C-999.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
    ]);

    $resource = (new UserResource($driver))->resolve();

    expect($resource)->toHaveKey('current_vehicle');
    expect($resource['current_vehicle'])->not->toBeNull();
    expect($resource['current_vehicle']['plate_number'])->toBe('51C-999.99');
});

test('user resource returns null for current vehicle when driver is not assigned to any vehicle', function () {
    $driver = User::factory()->create();

    $resource = (new UserResource($driver))->resolve();

    expect($resource)->toHaveKey('current_vehicle');
    expect($resource['current_vehicle'])->toBeNull();
});
