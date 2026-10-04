<?php

use App\Enums\CheckpointType;
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
use App\Services\Trip\TripStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->driver = User::factory()->create();
    $this->driver->assignRole($driverRole);

    $this->vehicle = Vehicle::create([
        'plate_number' => 'GUARD-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $this->driver->id,
    ]);

    DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'GRD', 'name' => 'Guard Area']);
    $customer = Customer::create(['code' => 'CUST-GRD', 'name' => 'Guard Customer', 'is_active' => true]);
    $this->deliveryLocation = Location::create([
        'code' => 'GRD-DEL',
        'name' => 'Guard Delivery',
        'lat' => 10.76,
        'lng' => 106.78,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-GUARD-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $makeOrder = function (string $code) use ($area, $customer) {
        $order = Order::create([
            'order_code' => $code,
            'type' => OrderType::Hhhk,
            'area_id' => $area->id,
            'customer_id' => $customer->id,
            'trip_id' => $this->trip->id,
            'pickup_address' => 'Pickup',
            'status' => OrderStatus::Sent,
            'created_by' => $this->driver->id,
        ]);

        $point = OrderDeliveryPoint::create([
            'order_id' => $order->id,
            'location_id' => $this->deliveryLocation->id,
            'sequence' => 1,
            'address' => 'Delivery',
            'status' => 'pending',
        ]);

        return [$order, $point];
    };

    [$this->order1, $this->dp1] = $makeOrder('ORD-GRD-001');
    [$this->order2, $this->dp2] = $makeOrder('ORD-GRD-002');

    Sanctum::actingAs($this->driver);
});

function guardsPostCheckpoint(Trip $trip, string $type, array $extra = []): TestResponse
{
    return test()->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => $type,
        'occurred_at' => now()->toIso8601String(),
        ...$extra,
    ]);
}

function guardsDriveToDelivering(Trip $trip): void
{
    guardsPostCheckpoint($trip, 'arrived_pickup')->assertSuccessful();
    guardsPostCheckpoint($trip, 'left_pickup')->assertSuccessful();
}

test('checkpoint_type cancelled returns 422 instead of a server error', function () {
    guardsPostCheckpoint($this->trip, 'cancelled')->assertStatus(422);
});

test('checkpoint on a cancelled trip returns 422', function () {
    $this->trip->update(['status' => TripStatus::Cancelled]);

    guardsPostCheckpoint($this->trip, 'arrived_pickup')->assertStatus(422);

    expect($this->trip->fresh()->status)->toBe(TripStatus::Cancelled);
});

test('checkpoint on a completed trip cannot move it back', function () {
    $this->trip->update(['status' => TripStatus::Completed]);

    guardsPostCheckpoint($this->trip, 'arrived_pickup')->assertStatus(422);

    expect($this->trip->fresh()->status)->toBe(TripStatus::Completed);
});

test('completing a cancelled trip via the API returns 422', function () {
    $this->trip->update(['status' => TripStatus::Cancelled]);

    $this->postJson("/api/driver/trips/{$this->trip->id}/complete")->assertStatus(422);

    expect($this->trip->fresh()->status)->toBe(TripStatus::Cancelled);
});

test('completing a trip with unfinished orders returns 422 and does not create a driver swap', function () {
    guardsDriveToDelivering($this->trip);

    $this->postJson("/api/driver/trips/{$this->trip->id}/complete")->assertStatus(422);

    expect($this->trip->fresh()->status)->toBe(TripStatus::Delivering);
    expect($this->order1->fresh()->status)->toBe(OrderStatus::InTransit);
});

test('a cancelled order does not block the trip from being delivered and completed', function () {
    guardsDriveToDelivering($this->trip);
    $this->order2->update(['status' => OrderStatus::Cancelled]);

    guardsPostCheckpoint($this->trip, 'arrived_delivery', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();
    guardsPostCheckpoint($this->trip, 'completed', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();

    expect($this->trip->fresh()->status)->toBe(TripStatus::Delivered);

    $this->postJson("/api/driver/trips/{$this->trip->id}/complete")->assertSuccessful();

    expect($this->trip->fresh()->status)->toBe(TripStatus::Completed);
});

test('ending one finished order while another is still delivering only records that order', function () {
    guardsDriveToDelivering($this->trip);

    $otherLocation = Location::create([
        'code' => 'GRD-OTHER',
        'name' => 'Other',
        'lat' => 10.7,
        'lng' => 106.7,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);
    $this->dp2->update(['location_id' => $otherLocation->id]);

    guardsPostCheckpoint($this->trip, 'arrived_delivery', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();
    guardsPostCheckpoint($this->trip, 'completed', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();

    guardsPostCheckpoint($this->trip, 'end', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();

    expect($this->trip->fresh()->status)->toBe(TripStatus::Delivering);
    expect($this->trip->checkpoints()->where('checkpoint_type', CheckpointType::End->value)->pluck('order_id')->all())
        ->toBe([$this->order1->id]);
});

test('end checkpoint is not copied to other orders at the same location', function () {
    guardsDriveToDelivering($this->trip);

    guardsPostCheckpoint($this->trip, 'arrived_delivery', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();
    guardsPostCheckpoint($this->trip, 'completed', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();

    expect($this->trip->fresh()->status)->toBe(TripStatus::Delivered);

    guardsPostCheckpoint($this->trip, 'end', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();

    $endOrderIds = $this->trip->checkpoints()
        ->where('checkpoint_type', CheckpointType::End->value)
        ->where('occurred_at', '<', now()->addSecond())
        ->pluck('order_id');

    expect($this->trip->fresh()->status)->toBe(TripStatus::Completed);
    expect($endOrderIds->filter(fn ($id) => $id === $this->order1->id))->toHaveCount(1);
});

test('re-sending an already recorded checkpoint is accepted without changing status', function () {
    guardsDriveToDelivering($this->trip);

    guardsPostCheckpoint($this->trip, 'arrived_pickup')->assertSuccessful();

    expect($this->trip->fresh()->status)->toBe(TripStatus::Delivering);
});

test('re-sending end for a completed trip is accepted', function () {
    $this->order1->update(['status' => OrderStatus::Completed]);
    $this->order2->update(['status' => OrderStatus::Completed]);
    $this->trip->update(['status' => TripStatus::Completed, 'completed_at' => now()]);

    guardsPostCheckpoint($this->trip, 'end')->assertSuccessful();
});

test('a driver who does not hold the trip gets no actions', function () {
    $otherDriver = User::factory()->create();

    expect(app(TripStateMachine::class)->availableActions($this->trip, $otherDriver))->toBe([])
        ->and(app(TripStateMachine::class)->availableOrderActions($this->order1, $otherDriver))->toBe([]);
});

test('order actions follow the progress of each order', function () {
    $machine = app(TripStateMachine::class);

    expect($machine->availableOrderActions($this->order1->fresh(), $this->driver))->toBe(['arrived_pickup']);

    guardsDriveToDelivering($this->trip);
    expect($machine->availableOrderActions($this->order1->fresh(), $this->driver))->toBe(['arrived_delivery', 'request_swap']);

    guardsPostCheckpoint($this->trip, 'arrived_delivery', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();
    expect($machine->availableOrderActions($this->order1->fresh(), $this->driver))->toBe(['completed', 'request_swap']);

    guardsPostCheckpoint($this->trip, 'completed', ['order_id' => $this->order1->id, 'delivery_point_id' => $this->dp1->id])->assertSuccessful();
    expect($machine->availableOrderActions($this->order1->fresh(), $this->driver))->toBe(['end']);
});

test('trip detail API returns status label and available actions', function () {
    $this->getJson("/api/driver/trips/{$this->trip->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.status_label', TripStatus::Pending->getLabel())
        ->assertJsonPath('data.available_actions', ['started', 'arrived_pickup']);
});
