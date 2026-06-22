<?php

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($driverRole);

    $this->vehicle = Vehicle::factory()->create(['current_mileage' => 15000]);

    Sanctum::actingAs($this->driver);
});

it('returns paginated completed trips for the driver', function () {
    Trip::factory()->count(3)->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(2),
        'completed_at' => now()->subDay(),
        'start_km' => 15000,
        'end_km' => 15400,
    ]);

    $response = $this->getJson('/api/driver/trips/history');

    $response->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'trip_code', 'status', 'started_at', 'completed_at', 'start_km', 'end_km', 'vehicle', 'checkpoints', 'orders'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])
        ->assertJsonPath('meta.total', 3);
});

it('filters by status', function () {
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::DriverSwap,
        'started_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/driver/trips/history?status=driver_swap');

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 1);
});

it('filters by date range', function () {
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(10),
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(3),
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/driver/trips/history?from_date='.now()->subDays(7)->format('Y-m-d').'&to_date='.now()->format('Y-m-d'));

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 2);
});

it('filters by vehicle_id', function () {
    $vehicle2 = Vehicle::factory()->create();
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $vehicle2->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);

    $response = $this->getJson("/api/driver/trips/history?vehicle_id={$this->vehicle->id}");

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 1);
});

it('returns empty result for driver with no trips', function () {
    $response = $this->getJson('/api/driver/trips/history');

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('data', []);
});

it('does not return trips belonging to other drivers', function () {
    $otherDriver = User::factory()->create();
    Trip::factory()->create([
        'driver_id' => $otherDriver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
    ]);

    $response = $this->getJson('/api/driver/trips/history');

    $response->assertSuccessful()
        ->assertJsonPath('meta.total', 0);
});

it('requires driver role', function () {
    $nonDriver = User::factory()->create();
    Sanctum::actingAs($nonDriver);

    $this->getJson('/api/driver/trips/history')
        ->assertStatus(403)
        ->assertJsonPath('message', 'Forbidden. Driver role required.');
});

it('throws 422 for invalid status filter', function () {
    $this->getJson('/api/driver/trips/history?status=InvalidStatus')
        ->assertStatus(422);
});
