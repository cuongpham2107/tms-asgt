<?php

use App\Enums\AssignmentEndReason;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripDriverAssignment;
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
    ]);

    $response = $this->getJson('/api/driver/trips/history');

    $response->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'trip_code', 'status', 'started_at', 'completed_at', 'vehicle', 'checkpoints', 'orders'],
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
        ->assertStatus(403);
});

it('throws 422 for invalid status filter', function () {
    $this->getJson('/api/driver/trips/history?status=InvalidStatus')
        ->assertStatus(422);
});

it('returns driver specific km when trip has multiple driver assignments', function () {
    $driverB = User::factory()->create();
    $driverB->assignRole('driver');

    $trip = Trip::factory()->create([
        'driver_id' => $driverB->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subHours(4),
        'completed_at' => now()->subHours(1),
        'total_km' => 100,
        'total_km_loaded' => 60,
        'total_km_empty' => 40,
    ]);

    TripDriverAssignment::create([
        'trip_id' => $trip->id,
        'driver_id' => $this->driver->id,
        'started_at' => now()->subHours(4),
        'ended_at' => now()->subHours(2),
        'end_reason' => AssignmentEndReason::ShiftHandover,
        'km' => 35,
        'km_loaded' => 20,
        'km_empty' => 15,
    ]);
    TripDriverAssignment::create([
        'trip_id' => $trip->id,
        'driver_id' => $driverB->id,
        'started_at' => now()->subHours(2),
        'ended_at' => now()->subHours(1),
        'end_reason' => AssignmentEndReason::TripFinished,
        'km' => 65,
        'km_loaded' => 40,
        'km_empty' => 25,
    ]);

    $responseA = $this->getJson('/api/driver/trips/history');
    $responseA->assertSuccessful()
        ->assertJsonPath('data.0.total_km', '100.0')
        ->assertJsonPath('data.0.driver_km', 35)
        ->assertJsonPath('data.0.driver_km_loaded', 20)
        ->assertJsonPath('data.0.driver_km_empty', 15)
        ->assertJsonPath('data.0.is_multi_driver', true);

    Sanctum::actingAs($driverB);
    $responseB = $this->getJson('/api/driver/trips/history');
    $responseB->assertSuccessful()
        ->assertJsonPath('data.0.total_km', '100.0')
        ->assertJsonPath('data.0.driver_km', 65)
        ->assertJsonPath('data.0.driver_km_loaded', 40)
        ->assertJsonPath('data.0.driver_km_empty', 25)
        ->assertJsonPath('data.0.is_multi_driver', true);
});
