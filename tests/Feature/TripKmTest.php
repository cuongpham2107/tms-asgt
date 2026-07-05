<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripKmCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->role = Role::create(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($this->role);

    $this->vehicle = Vehicle::create([
        'plate_number' => 'KM-TEST-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 50000,
    ]);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'KM-TEST',
        'name' => 'KM Test',
    ]);

    $this->customer = Customer::create([
        'code' => 'KM-CUST',
        'name' => 'KM Customer',
        'is_active' => true,
    ]);
});

function createTripWithOrder($test, int $startKm): Trip
{
    $trip = Trip::create([
        'trip_code' => 'TRIP-KM-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $test->vehicle->id,
        'driver_id' => $test->driver->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
        'start_km' => $startKm,
    ]);

    return $trip;
}

function createOrderOnTrip(Trip $trip, User $driver, $area, $customer): Order
{
    return Order::create([
        'order_code' => 'ORD-KM-'.fake()->unique()->randomNumber(),
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'created_by' => $driver->id,
    ]);
}

function createCheckpoint(Trip $trip, Order $order, string $type, int $kmReading, ?DriverShift $shift = null): TripCheckpoint
{
    return TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $trip->driver_id,
        'shift_id' => $shift?->id,
        'checkpoint_type' => $type,
        'occurred_at' => now(),
        'km_reading' => $kmReading,
    ]);
}

test('calculates km for trip with 1 order', function () {
    $trip = createTripWithOrder($this, 10000);
    $order = createOrderOnTrip($trip, $this->driver, $this->area, $this->customer);

    createCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, 10010);
    createCheckpoint($trip, $order, CheckpointType::Completed->value, 10090);

    $trip->update(['end_km' => 10090]);
    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    expect((float) $trip->total_km)->toBe(90.0);
    expect((float) $trip->total_km_loaded)->toBe(80.0);
    expect((float) $trip->total_km_empty)->toBe(10.0);
});

test('calculates km for trip with 2 sequential orders', function () {
    $trip = createTripWithOrder($this, 20000);
    $orderA = createOrderOnTrip($trip, $this->driver, $this->area, $this->customer);
    $orderB = createOrderOnTrip($trip, $this->driver, $this->area, $this->customer);

    createCheckpoint($trip, $orderA, CheckpointType::ArrivedPickup->value, 20010);
    createCheckpoint($trip, $orderA, CheckpointType::Completed->value, 20040);
    createCheckpoint($trip, $orderB, CheckpointType::ArrivedPickup->value, 20060);
    createCheckpoint($trip, $orderB, CheckpointType::Completed->value, 20100);

    $trip->update(['end_km' => 20100]);
    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    // loaded: (20040-20010) + (20100-20060) = 30 + 40 = 70
    expect((float) $trip->total_km_loaded)->toBe(70.0);
    // empty: total(100) - loaded(70) = 30
    expect((float) $trip->total_km_empty)->toBe(30.0);
});

test('calculates partial km for incomplete trip (driver swap scenario)', function () {
    $trip = createTripWithOrder($this, 30000);
    $order = createOrderOnTrip($trip, $this->driver, $this->area, $this->customer);

    createCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, 30010);
    // No completed checkpoint — trip is in-progress

    app(TripKmCalculatorService::class)->calculate($trip, endKm: 30060);

    $trip->refresh();
    expect((float) $trip->total_km)->toBe(60.0);
    // loaded: from arrived_pickup(30010) to end(30060) = 50
    expect((float) $trip->total_km_loaded)->toBe(50.0);
    // empty: total(60) - loaded(50) = 10
    expect((float) $trip->total_km_empty)->toBe(10.0);
    // end_km được set từ param
    expect((float) $trip->end_km)->toBe(30060.0);
});

test('skips calculation when start_km is not set', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-KM-SKIP',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
    ]);

    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    expect($trip->total_km)->toBeNull();
    expect($trip->total_km_loaded)->toBeNull();
    expect($trip->total_km_empty)->toBeNull();
});

test('Trip::complete() triggers calculator', function () {
    $trip = createTripWithOrder($this, 40000);
    $order = createOrderOnTrip($trip, $this->driver, $this->area, $this->customer);

    createCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, 40010);
    createCheckpoint($trip, $order, CheckpointType::Completed->value, 40090);

    $trip->complete(endKm: 40090);

    $trip->refresh();
    expect($trip->status)->toBe(TripStatus::Completed);
    expect((float) $trip->total_km)->toBe(90.0);
    expect((float) $trip->total_km_loaded)->toBe(80.0);
    expect((float) $trip->total_km_empty)->toBe(10.0);
});

test('skips calculation when start_km is 0', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-KM-ZERO',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
        'start_km' => 0,
    ]);

    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    expect($trip->total_km)->toBeNull();
    expect($trip->total_km_loaded)->toBeNull();
    expect($trip->total_km_empty)->toBeNull();
});

test('skips calculation when total_km_loaded already set (re-entry guard)', function () {
    $trip = createTripWithOrder($this, 50000);
    $order = createOrderOnTrip($trip, $this->driver, $this->area, $this->customer);

    createCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, 50010);
    createCheckpoint($trip, $order, CheckpointType::Completed->value, 50090);

    $trip->update(['end_km' => 50090]);
    app(TripKmCalculatorService::class)->calculate($trip);
    $trip->refresh();

    $loadedBefore = (float) $trip->total_km_loaded;

    app(TripKmCalculatorService::class)->calculate($trip);
    $trip->refresh();

    expect((float) $trip->total_km_loaded)->toBe($loadedBefore);
});
