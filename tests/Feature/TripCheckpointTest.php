<?php

use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'TEST',
        'name' => 'Test Area',
    ]);
    $this->customer = Customer::create([
        'code' => 'CUST-TEST',
        'name' => 'Test Customer',
        'is_active' => true,
    ]);
    $this->vehicle = Vehicle::create([
        'plate_number' => 'TEST-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 50000,
    ]);
    $this->driver = User::factory()->create(['name' => 'Driver']);
    $this->driver->assignRole($this->driverRole);
    $this->vehicle->update(['current_driver_id' => $this->driver->id]);

    $this->pickupLocation = Location::create([
        'code' => 'PICKUP',
        'name' => 'Pickup Location',
        'lat' => 10.818889,
        'lng' => 106.651944,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);
    $this->deliveryLocation = Location::create([
        'code' => 'DELIVERY',
        'name' => 'Delivery Location',
        'lat' => 10.764722,
        'lng' => 106.781944,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-TEST-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $this->order1 = Order::create([
        'order_code' => 'ORD-TEST-001',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Pickup address',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $this->order2 = Order::create([
        'order_code' => 'ORD-TEST-002',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Pickup address 2',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $this->dp1 = OrderDeliveryPoint::create([
        'order_id' => $this->order1->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'Delivery address',
        'status' => 'pending',
    ]);

    $this->dp2 = OrderDeliveryPoint::create([
        'order_id' => $this->order2->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'Delivery address 2',
        'status' => 'pending',
    ]);

    Sanctum::actingAs($this->driver);
});

test('started creates checkpoints for all orders in trip', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
        'gps_lat' => 10.818889,
        'gps_lng' => 106.651944,
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Started);

    $checkpoints = $this->trip->checkpoints;
    expect($checkpoints)->toHaveCount(2);
    expect($checkpoints->pluck('order_id')->sort()->values()->toArray())->toBe([$this->order1->id, $this->order2->id]);
});

test('started updates trip.shift_id from driver active shift', function () {
    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect((int) $this->trip->shift_id)->toBe($shift->id);
});

test('started with km_reading fails validation', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'km_reading' => 50010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422);
});

test('arrived_pickup requires km_reading', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_pickup',
        'km_reading' => 50010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::ArrivedPickup);
});

test('arrived_pickup without km_reading fails', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422);
});

test('arrived_delivery requires order_id and delivery_point_id', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::ArrivedDelivery);
    expect($this->dp1->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);
});

test('arrived_delivery without order_id fails', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422);
});

test('completed without order_id fails validation', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'km_reading' => 50050,
    ])->assertStatus(422);
});

test('completed completes order and auto-completes trip if last order', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50050,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($this->order1->fresh()->status)->toBe(OrderStatus::Completed);

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Delivering);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order2->id,
        'delivery_point_id' => $this->dp2->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $this->order2->id,
        'delivery_point_id' => $this->dp2->id,
        'km_reading' => 50090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($this->order2->fresh()->status)->toBe(OrderStatus::Completed);

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Completed);
});

test('unauthorized driver gets 403', function () {
    $otherDriver = User::factory()->create();
    $otherDriver->assignRole($this->driverRole);
    Sanctum::actingAs($otherDriver);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
    ])->assertStatus(403);
});

test('order not in trip returns 422', function () {
    $otherTrip = Trip::create([
        'trip_code' => 'TRIP-OTHER',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$otherTrip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422);
});

test('left_pickup updates trip status to delivering', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Delivering);
});
