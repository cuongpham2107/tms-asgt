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
use App\Services\Order\OrderDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driver = User::factory()->create();
    $this->vehicle = Vehicle::create(['plate_number' => '29C-DISPATCH', 'vehicle_type' => VehicleType::Normal, 'owner' => 'CLC', 'is_active' => true, 'status' => VehicleStatus::On, 'type' => VehicleOwnerType::Company, 'current_mileage' => 1000]);
    $this->order = Order::create(['order_code' => 'ASG-DISPATCH', 'type' => OrderType::Hhhk, 'area_id' => Area::create(['code' => 'NBA', 'name' => 'NBA'])->id, 'customer_id' => Customer::create(['code' => 'C', 'name' => 'C'])->id, 'status' => OrderStatus::Draft, 'created_by' => $this->driver->id]);
});

test('assigning creates a waiting trip, sends the order and marks the vehicle running', function () {
    $trip = app(OrderDispatchService::class)->assign($this->order, $this->vehicle->id, $this->driver->id);

    $order = $this->order->fresh();
    expect($trip->status)->toBe(TripStatus::Pending)
        ->and($trip->driver_id)->toBe($this->driver->id)
        ->and($order->trip_id)->toBe($trip->id)
        ->and($order->status)->toBe(OrderStatus::Sent)
        ->and($order->sent_at)->not->toBeNull()
        ->and($this->vehicle->fresh()->status)->toBe(VehicleStatus::Running);
});

test('assigning without sending keeps the order assigned', function () {
    app(OrderDispatchService::class)->assign($this->order, $this->vehicle->id, $this->driver->id, OrderStatus::Assigned);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Assigned)
        ->and($this->order->fresh()->sent_at)->toBeNull();
});

test('recalling an order returns it to draft and removes its empty trip', function () {
    $trip = app(OrderDispatchService::class)->assign($this->order, $this->vehicle->id, $this->driver->id);

    app(OrderDispatchService::class)->unsend($this->order->fresh());

    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::Draft)
        ->and($order->trip_id)->toBeNull()
        ->and(Trip::query()->find($trip->id))->toBeNull();
});
