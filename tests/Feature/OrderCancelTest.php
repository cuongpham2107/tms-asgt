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
