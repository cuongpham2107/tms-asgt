<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'TEST',
        'name' => 'Test',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-TEST',
        'name' => 'Test Customer',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '51A-99999',
        'owner' => 'ASGT',
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->driver = User::create([
        'name' => 'Test Driver',
        'email' => 'driver@test.com',
        'password' => bcrypt('password'),
    ]);

    $this->creator = User::create([
        'name' => 'Test Creator',
        'email' => 'creator@test.com',
        'password' => bcrypt('password'),
    ]);
});

it('sets trip_id to null when cancelling an order with a trip', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-CANCEL-TEST-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $order = Order::create([
        'order_code' => 'OD-CANCEL-TEST-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Test cargo',
        'status' => OrderStatus::Sent,
        'trip_id' => $trip->id,
        'trip_sequence' => 1,
        'created_by' => $this->creator->id,
    ]);

    Order::query()->whereKey($order->id)->update([
        'status' => OrderStatus::Cancelled->value,
        'cancelled_at' => now(),
        'cancel_reason' => 'Test cancel',
        'trip_id' => null,
    ]);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Cancelled);
    expect($order->trip_id)->toBeNull();
    expect($order->cancel_reason)->toBe('Test cancel');
    expect($order->cancelled_at)->not->toBeNull();
});

it('cancels the trip when the last order is cancelled', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-CANCEL-TEST-2',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $order = Order::create([
        'order_code' => 'OD-CANCEL-TEST-2',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Test cargo',
        'status' => OrderStatus::Sent,
        'trip_id' => $trip->id,
        'trip_sequence' => 1,
        'created_by' => $this->creator->id,
    ]);

    $tripId = $order->trip_id;

    Order::query()->whereKey($order->id)->update([
        'status' => OrderStatus::Cancelled->value,
        'cancelled_at' => now(),
        'cancel_reason' => 'Last order cancel',
        'trip_id' => null,
    ]);

    $remaining = Order::query()
        ->where('trip_id', $tripId)
        ->where('status', '!=', OrderStatus::Cancelled->value)
        ->count();

    if ($remaining === 0) {
        $trip->update([
            'status' => TripStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    $trip->refresh();

    expect($trip->status)->toBe(TripStatus::Cancelled);
    expect($trip->cancelled_at)->not->toBeNull();
});

it('does not cancel the trip when other orders remain', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-CANCEL-TEST-3',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $order1 = Order::create([
        'order_code' => 'OD-CANCEL-TEST-3A',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Cargo A',
        'status' => OrderStatus::Sent,
        'trip_id' => $trip->id,
        'trip_sequence' => 1,
        'created_by' => $this->creator->id,
    ]);

    $order2 = Order::create([
        'order_code' => 'OD-CANCEL-TEST-3B',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Cargo B',
        'status' => OrderStatus::Sent,
        'trip_id' => $trip->id,
        'trip_sequence' => 2,
        'created_by' => $this->creator->id,
    ]);

    $tripId = $order1->trip_id;

    Order::query()->whereKey($order1->id)->update([
        'status' => OrderStatus::Cancelled->value,
        'cancelled_at' => now(),
        'cancel_reason' => 'Cancel one of two',
        'trip_id' => null,
    ]);

    $remaining = Order::query()
        ->where('trip_id', $tripId)
        ->where('id', '!=', $order1->id)
        ->where('status', '!=', OrderStatus::Cancelled->value)
        ->count();

    expect($remaining)->toBe(1);

    $trip->refresh();

    expect($trip->status)->not->toBe(TripStatus::Cancelled);
    expect($trip->cancelled_at)->toBeNull();
});

it('cancels the trip only after the last remaining order is cancelled', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-CANCEL-TEST-4',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $order1 = Order::create([
        'order_code' => 'OD-CANCEL-TEST-4A',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Cargo A',
        'status' => OrderStatus::Sent,
        'trip_id' => $trip->id,
        'trip_sequence' => 1,
        'created_by' => $this->creator->id,
    ]);

    $order2 = Order::create([
        'order_code' => 'OD-CANCEL-TEST-4B',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Cargo B',
        'status' => OrderStatus::Sent,
        'trip_id' => $trip->id,
        'trip_sequence' => 2,
        'created_by' => $this->creator->id,
    ]);

    $tripId = $order1->trip_id;

    // Cancel first order
    Order::query()->whereKey($order1->id)->update([
        'status' => OrderStatus::Cancelled->value,
        'cancelled_at' => now(),
        'cancel_reason' => 'Cancel first',
        'trip_id' => null,
    ]);

    // Trip should still be active (one order remains)
    $trip->refresh();
    expect($trip->status)->not->toBe(TripStatus::Cancelled);

    // Cancel second (last) order
    Order::query()->whereKey($order2->id)->update([
        'status' => OrderStatus::Cancelled->value,
        'cancelled_at' => now(),
        'cancel_reason' => 'Cancel last',
        'trip_id' => null,
    ]);

    $remaining = Order::query()
        ->where('trip_id', $tripId)
        ->where('status', '!=', OrderStatus::Cancelled->value)
        ->count();

    if ($remaining === 0) {
        $trip->update([
            'status' => TripStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    $trip->refresh();

    expect($trip->status)->toBe(TripStatus::Cancelled);
    expect($trip->cancelled_at)->not->toBeNull();
});
