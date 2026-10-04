<?php

use App\Enums\DriverSwapReason;
use App\Enums\TripStatus;
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

    $this->driver = User::factory()->create();
    $this->driver->assignRole('driver');

    $this->vehicle = Vehicle::factory()->create(['current_mileage' => 15000]);

    Sanctum::actingAs($this->driver);
});

it('returns correct stats counts including completed trips with draft orders', function () {
    $trip1 = Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(2),
        'completed_at' => now()->subDay(),
        'total_km' => 400,
        'total_km_loaded' => 300,
        'total_km_empty' => 100,
    ]);

    $trip2 = Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(1),
        'completed_at' => now()->subHours(5),
        'total_km' => 400,
        'total_km_loaded' => 200,
        'total_km_empty' => 200,
    ]);

    $response = $this->getJson('/api/driver/trips/stats');

    $response->assertSuccessful()
        ->assertJsonPath('data.completed', 2)
        ->assertJsonPath('data.assigned', 0)
        ->assertJsonPath('data.in_progress', 0)
        ->assertJsonPath('data.total_km', 800)
        ->assertJsonPath('data.total_km_loaded', 500)
        ->assertJsonPath('data.total_km_empty', 300);
});

it('counts return trips as in_progress', function () {
    $trip = Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Started,
        'started_at' => now()->subHours(2),
    ]);

    $response = $this->getJson('/api/driver/trips/stats');

    $response->assertSuccessful()
        ->assertJsonPath('data.in_progress', 1)
        ->assertJsonPath('data.completed', 0);
});

it('filters stats by date range', function () {
    $oldDate = Carbon::now()->subDays(10);
    $recentDate = Carbon::now()->subDays(3);

    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => $oldDate,
        'completed_at' => $oldDate->copy()->addDay(),
        'created_at' => $oldDate,
    ]);

    $trip2 = Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => $recentDate,
        'completed_at' => $recentDate->copy()->addDay(),
        'created_at' => $recentDate,
    ]);

    $response = $this->getJson('/api/driver/trips/stats?from_date='.now()->subDays(7)->format('Y-m-d').'&to_date='.now()->format('Y-m-d'));

    $response->assertSuccessful()
        ->assertJsonPath('data.completed', 1);
});

it('does not return stats for other drivers', function () {
    $otherDriver = User::factory()->create();
    $otherDriver->assignRole('driver');

    Trip::factory()->create([
        'driver_id' => $otherDriver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/driver/trips/stats');

    $response->assertSuccessful()
        ->assertJsonPath('data.completed', 0)
        ->assertJsonPath('data.in_progress', 0)
        ->assertJsonPath('data.assigned', 0);
});

it('includes km from cancelled trips that have driven km', function () {
    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subHours(5),
        'completed_at' => now()->subHours(3),
        'total_km' => 150,
        'total_km_loaded' => 100,
        'total_km_empty' => 50,
    ]);

    Trip::factory()->create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Cancelled,
        'started_at' => now()->subHours(2),
        'cancelled_at' => now()->subHour(),
        'total_km' => 100,
        'total_km_loaded' => 0,
        'total_km_empty' => 100,
    ]);

    $response = $this->getJson('/api/driver/trips/stats');

    $response->assertSuccessful()
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.total_km', 250)
        ->assertJsonPath('data.total_km_loaded', 100)
        ->assertJsonPath('data.total_km_empty', 150);
});

it('calculates driver km for swapped trips from db', function () {
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

    DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $this->driver->id,
        'to_driver_id' => $driverB->id,
        'reason' => DriverSwapReason::ShiftHandover,
        'created_by' => $this->driver->id,
    ]);

    $response = $this->getJson('/api/driver/trips/stats');

    $response->assertSuccessful()
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.total_km', 100)
        ->assertJsonPath('data.total_km_loaded', 60)
        ->assertJsonPath('data.total_km_empty', 40);

    Sanctum::actingAs($driverB);
    $responseB = $this->getJson('/api/driver/trips/stats');

    $responseB->assertSuccessful()
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.total_km', 100)
        ->assertJsonPath('data.total_km_loaded', 60)
        ->assertJsonPath('data.total_km_empty', 40);
});
