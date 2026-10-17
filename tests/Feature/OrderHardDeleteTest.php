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
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

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

    $this->creator = User::create([
        'name' => 'Test Creator',
        'email' => 'creator@test.com',
        'password' => bcrypt('password'),
    ]);
});

it('hard deletes an order instead of soft deleting', function () {
    $order = Order::create([
        'order_code' => 'OD-HARD-DELETE-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Test cargo',
        'status' => OrderStatus::Draft,
        'created_by' => $this->creator->id,
    ]);

    $order->delete();

    expect(Order::find($order->id))->toBeNull();
    expect(Order::query()->whereKey($order->id)->exists())->toBeFalse();
});

it('does not have a deleted_at column on orders', function () {
    expect(Schema::hasColumn('orders', 'deleted_at'))->toBeFalse();
});

it('automatically deletes associated orders and delivery points when a trip is deleted', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-TEST-CASCADE-DEL',
        'status' => TripStatus::Pending,
        'vehicle_id' => $this->vehicle->id,
    ]);

    $order = Order::create([
        'order_code' => 'OD-CASCADE-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Test cargo',
        'status' => OrderStatus::Sent,
        'created_by' => $this->creator->id,
        'trip_id' => $trip->id,
    ]);

    $point = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'sequence' => 1,
    ]);

    expect(Order::where('id', $order->id)->exists())->toBeTrue()
        ->and(OrderDeliveryPoint::where('id', $point->id)->exists())->toBeTrue();

    $trip->delete();

    expect(Order::where('id', $order->id)->exists())->toBeFalse()
        ->and(OrderDeliveryPoint::where('id', $point->id)->exists())->toBeFalse();
});
