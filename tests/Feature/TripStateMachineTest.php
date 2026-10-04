<?php

use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\TripStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stateMachine = app(TripStateMachine::class);
    $this->user = User::factory()->create();
    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-888.88',
        'status' => VehicleStatus::On,
        'owner' => 'Công ty',
    ]);
    $this->area = Area::create(['name' => 'Khu vực Hà Nội', 'code' => 'HN']);
    $this->customer = Customer::create(['name' => 'Khách hàng Samsung', 'code' => 'SAMSUNG']);
});

function createTestTrip(Vehicle $vehicle, TripStatus $status = TripStatus::Pending): Trip
{
    return Trip::create([
        'trip_code' => Trip::generateTripCode(),
        'vehicle_id' => $vehicle->id,
        'status' => $status,
        'started_at' => $status === TripStatus::Pending ? null : now(),
    ]);
}

function createTestOrder(Trip $trip, OrderStatus $status = OrderStatus::Assigned, ?int $areaId = null, ?int $customerId = null): Order
{
    $areaId = $areaId ?? Area::first()?->id ?? Area::create(['name' => 'KV', 'code' => 'KV'])->id;
    $customerId = $customerId ?? Customer::first()?->id ?? Customer::create(['name' => 'KH', 'code' => 'KH'])->id;

    return Order::create([
        'order_code' => 'ORD-'.uniqid(),
        'trip_id' => $trip->id,
        'status' => $status,
        'type' => OrderType::Hhhk,
        'area_id' => $areaId,
        'customer_id' => $customerId,
        'created_by' => User::first()?->id ?? 1,
    ]);
}

function createDeliveryPoint(Order $order, OrderDeliveryPointStatus $status = OrderDeliveryPointStatus::Pending): OrderDeliveryPoint
{
    return OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => $status,
        'address' => '123 Đường Test, Hà Nội',
    ]);
}

// === TABLE 1 & 2: VALID TRANSITIONS ===

test('pending trip transitions to started on started checkpoint and cascades to orders and vehicle', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Pending);
    $order = createTestOrder($trip, OrderStatus::Assigned);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::Started, collect());

    $trip->refresh();
    $order->refresh();
    $this->vehicle->refresh();

    expect($trip->status)->toBe(TripStatus::Started)
        ->and($trip->started_at)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Sent)
        ->and($this->vehicle->status)->toBe(VehicleStatus::Running);
});

test('started trip transitions to arrived_pickup on arrived_pickup checkpoint', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Started);
    createTestOrder($trip, OrderStatus::Sent);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::ArrivedPickup, collect());

    expect($trip->refresh()->status)->toBe(TripStatus::ArrivedPickup);
});

test('arrived_pickup trip transitions to delivering on left_pickup checkpoint and orders become in_transit', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::ArrivedPickup);
    $order = createTestOrder($trip, OrderStatus::Sent);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::LeftPickup, collect());

    $trip->refresh();
    $order->refresh();

    expect($trip->status)->toBe(TripStatus::Delivering)
        ->and($order->status)->toBe(OrderStatus::InTransit);
});

test('delivering trip transitions to arrived_delivery and updates delivery point to arrived', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Delivering);
    $order = createTestOrder($trip, OrderStatus::InTransit);
    $dp = createDeliveryPoint($order, OrderDeliveryPointStatus::Pending);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::ArrivedDelivery, collect([
        ['delivery_point_id' => $dp->id, 'order_id' => $order->id, 'occurred_at' => now()],
    ]));

    $trip->refresh();
    $dp->refresh();

    expect($trip->status)->toBe(TripStatus::ArrivedDelivery)
        ->and($dp->status)->toBe(OrderDeliveryPointStatus::Arrived)
        ->and($dp->arrived_at)->not->toBeNull();
});

test('chuyến 2 đơn, giao xong đơn 1 thì trip ở delivering', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::ArrivedDelivery);
    $order1 = createTestOrder($trip, OrderStatus::InTransit);
    $dp1 = createDeliveryPoint($order1, OrderDeliveryPointStatus::Arrived);

    $order2 = createTestOrder($trip, OrderStatus::InTransit);
    $dp2 = createDeliveryPoint($order2, OrderDeliveryPointStatus::Pending);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::Completed, collect([
        ['delivery_point_id' => $dp1->id, 'order_id' => $order1->id, 'occurred_at' => now()],
    ]));

    $trip->refresh();
    $order1->refresh();
    $order2->refresh();
    $dp1->refresh();

    expect($dp1->status)->toBe(OrderDeliveryPointStatus::Delivered)
        ->and($order1->status)->toBe(OrderStatus::Completed)
        ->and($order2->status)->toBe(OrderStatus::InTransit)
        ->and($trip->status)->toBe(TripStatus::Delivering);
});

test('chuyến 2 đơn, 1 đơn huỷ + 1 đơn hoàn thành thì trip chuyển sang delivered', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Delivering);
    $order1 = createTestOrder($trip, OrderStatus::InTransit);
    $dp1 = createDeliveryPoint($order1, OrderDeliveryPointStatus::Arrived);

    $order2 = createTestOrder($trip, OrderStatus::Cancelled);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::Completed, collect([
        ['delivery_point_id' => $dp1->id, 'order_id' => $order1->id, 'occurred_at' => now()],
    ]));

    $trip->refresh();
    $order1->refresh();

    expect($order1->status)->toBe(OrderStatus::Completed)
        ->and($trip->status)->toBe(TripStatus::Delivered);
});

test('delivered trip transitions to completed on end checkpoint and vehicle returns to On', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Delivered);
    $order = createTestOrder($trip, OrderStatus::Completed);
    $this->vehicle->update(['status' => VehicleStatus::Running]);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::End, collect());

    $trip->refresh();
    $this->vehicle->refresh();

    expect($trip->status)->toBe(TripStatus::Completed)
        ->and($trip->completed_at)->not->toBeNull()
        ->and($this->vehicle->status)->toBe(VehicleStatus::On);
});

test('xe vẫn bận khi đơn còn in_transit', function () {
    $trip1 = createTestTrip($this->vehicle, TripStatus::Delivered);
    $order1 = createTestOrder($trip1, OrderStatus::Completed);

    // Another trip on the same vehicle is delivering with in_transit order
    $trip2 = createTestTrip($this->vehicle, TripStatus::Delivering);
    $order2 = createTestOrder($trip2, OrderStatus::InTransit);

    $this->vehicle->update(['status' => VehicleStatus::Running]);

    $this->stateMachine->applyCheckpoint($trip1, CheckpointType::End, collect());

    $this->vehicle->refresh();

    expect($trip1->refresh()->status)->toBe(TripStatus::Completed)
        ->and($this->vehicle->status)->toBe(VehicleStatus::Running);
});

test('requestSwap saves status_before_swap and sets trip and orders to driver_swap', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Delivering);
    $order = createTestOrder($trip, OrderStatus::InTransit);

    $this->stateMachine->requestSwap($trip);

    $trip->refresh();
    $order->refresh();

    expect($trip->status)->toBe(TripStatus::DriverSwap)
        ->and($trip->status_before_swap)->toBe(TripStatus::Delivering)
        ->and($order->status)->toBe(OrderStatus::DriverSwap);
});

test('restoreAfterSwap restores trip to status_before_swap and orders to in_transit if delivering', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::DriverSwap);
    $trip->update(['status_before_swap' => TripStatus::Delivering]);
    $order = createTestOrder($trip, OrderStatus::DriverSwap);

    $this->stateMachine->restoreAfterSwap($trip);

    $trip->refresh();
    $order->refresh();

    expect($trip->status)->toBe(TripStatus::Delivering)
        ->and($trip->status_before_swap)->toBeNull()
        ->and($order->status)->toBe(OrderStatus::InTransit);
});

test('restoreAfterSwap restores orders to sent if status_before_swap was started', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::DriverSwap);
    $trip->update(['status_before_swap' => TripStatus::Started]);
    $order = createTestOrder($trip, OrderStatus::DriverSwap);

    $this->stateMachine->restoreAfterSwap($trip);

    $trip->refresh();
    $order->refresh();

    expect($trip->status)->toBe(TripStatus::Started)
        ->and($order->status)->toBe(OrderStatus::Sent);
});

test('cancelTrip cancels all unclosed orders and syncs vehicle to On', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Started);
    $order = createTestOrder($trip, OrderStatus::Sent);
    $this->vehicle->update(['status' => VehicleStatus::Running]);

    $this->stateMachine->cancelTrip($trip, $this->user, 'Xe hỏng đột xuất');

    $trip->refresh();
    $order->refresh();
    $this->vehicle->refresh();

    expect($trip->status)->toBe(TripStatus::Cancelled)
        ->and($trip->cancelled_at)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancel_reason)->toBe('Xe hỏng đột xuất')
        ->and($this->vehicle->status)->toBe(VehicleStatus::On);
});

test('sendOrder transitions order from assigned to sent', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Pending);
    $order = createTestOrder($trip, OrderStatus::Assigned);

    $this->stateMachine->sendOrder($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Sent);
});

test('recallOrder transitions order from sent to assigned when trip is not left pickup', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::ArrivedPickup);
    $order = createTestOrder($trip, OrderStatus::Sent);

    $this->stateMachine->recallOrder($order);

    expect($order->refresh()->status)->toBe(OrderStatus::Assigned);
});

test('cancelOrder cancels order and cascades cancel to trip if all orders are cancelled', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Started);
    $order = createTestOrder($trip, OrderStatus::Sent);
    $this->vehicle->update(['status' => VehicleStatus::Running]);

    $this->stateMachine->cancelOrder($order, $this->user, 'Khách huỷ');

    $order->refresh();
    $trip->refresh();
    $this->vehicle->refresh();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($trip->status)->toBe(TripStatus::Cancelled)
        ->and($this->vehicle->status)->toBe(VehicleStatus::On);
});

test('cancelOrder on 1 of 2 orders cascades trip to delivered if remaining order is completed', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Delivering);
    $order1 = createTestOrder($trip, OrderStatus::Completed);
    $order2 = createTestOrder($trip, OrderStatus::InTransit);

    $this->stateMachine->cancelOrder($order2, $this->user, 'Huỷ đơn phụ');

    $trip->refresh();
    $order2->refresh();

    expect($order2->status)->toBe(OrderStatus::Cancelled)
        ->and($trip->status)->toBe(TripStatus::Delivered);
});

test('auto-start transitions pending trip to started before applying another checkpoint', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Pending);
    $order = createTestOrder($trip, OrderStatus::Assigned);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::ArrivedPickup, collect());

    $trip->refresh();
    $order->refresh();

    expect($trip->status)->toBe(TripStatus::ArrivedPickup)
        ->and($order->status)->toBe(OrderStatus::Sent);
});

// === INVALID TRANSITIONS (At least 6 tests) ===

test('checkpoint trên trip cancelled throws InvalidTransitionException', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Cancelled);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::Started, collect());
})->throws(InvalidTransitionException::class, 'Không thể cập nhật chuyến đã bị huỷ.');

test('checkpoint trên trip completed throws InvalidTransitionException', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Completed);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::Started, collect());
})->throws(InvalidTransitionException::class, 'Không thể cập nhật chuyến đã hoàn thành.');

test('started checkpoint on already started trip throws InvalidTransitionException', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Started);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::Started, collect());
})->throws(InvalidTransitionException::class, 'Không thể bắt đầu chuyến từ trạng thái Đã bắt đầu.');

test('left_pickup on started trip without arrived_pickup throws InvalidTransitionException', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Started);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::LeftPickup, collect());
})->throws(InvalidTransitionException::class, 'Không thể cập nhật Rời điểm lấy hàng từ trạng thái Đã bắt đầu.');

test('end checkpoint on delivering trip throws InvalidTransitionException', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Delivering);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::End, collect());
})->throws(InvalidTransitionException::class, 'Không thể kết thúc chuyến khi chưa giao xong toàn bộ đơn hàng');

test('recallOrder after trip left_pickup throws InvalidTransitionException', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Delivering);
    $order = createTestOrder($trip, OrderStatus::Sent);

    $this->stateMachine->recallOrder($order);
})->throws(InvalidTransitionException::class, 'Không thể thu hồi đơn khi xe đã rời điểm lấy hàng.');

test('cancelOrder when delivery point arrived throws InvalidTransitionException', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::ArrivedDelivery);
    $order = createTestOrder($trip, OrderStatus::InTransit);
    createDeliveryPoint($order, OrderDeliveryPointStatus::Arrived);

    $this->stateMachine->cancelOrder($order, $this->user);
})->throws(InvalidTransitionException::class, 'Không thể huỷ đơn hàng khi đã đến điểm giao.');

test('cancelled checkpoint directly via applyCheckpoint throws InvalidTransitionException', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Started);

    $this->stateMachine->applyCheckpoint($trip, CheckpointType::Cancelled, collect());
})->throws(InvalidTransitionException::class, 'Không dùng checkpoint để huỷ chuyến.');

test('availableActions returns correct actions for trip statuses', function () {
    $trip = createTestTrip($this->vehicle, TripStatus::Pending);
    expect($this->stateMachine->availableActions($trip))->toBe(['started']);

    $trip->status = TripStatus::Started;
    expect($this->stateMachine->availableActions($trip))->toBe(['arrived_pickup', 'request_swap']);

    $trip->status = TripStatus::ArrivedPickup;
    expect($this->stateMachine->availableActions($trip))->toBe(['left_pickup', 'request_swap']);

    $trip->status = TripStatus::Delivering;
    expect($this->stateMachine->availableActions($trip))->toBe(['arrived_delivery', 'completed', 'request_swap']);

    $trip->status = TripStatus::ArrivedDelivery;
    expect($this->stateMachine->availableActions($trip))->toBe(['arrived_delivery', 'completed', 'request_swap']);

    $trip->status = TripStatus::Delivered;
    expect($this->stateMachine->availableActions($trip))->toBe(['end']);

    $trip->status = TripStatus::Completed;
    expect($this->stateMachine->availableActions($trip))->toBe([]);
});
