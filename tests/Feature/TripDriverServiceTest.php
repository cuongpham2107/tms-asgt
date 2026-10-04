<?php

use App\Enums\AssignmentEndReason;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripDriverAssignment;
use App\Models\User;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\TripDriverService;
use App\Services\Trip\TripStateMachine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->sentPushes = collect();
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->andReturnUsing(function (CloudMessage $message) {
        $this->sentPushes->push($message->jsonSerialize());

        return [];
    });
    app()->instance(DriverNotificationService::class, new DriverNotificationService($messaging));

    $this->driverA = User::factory()->create(['fcm_token' => 'token-a']);
    $this->driverB = User::factory()->create(['fcm_token' => 'token-b']);
    $this->service = app(TripDriverService::class);

    $this->trip = Trip::factory()->create(['driver_id' => null, 'status' => TripStatus::Pending]);
    $this->service->openAssignment($this->trip, $this->driverA);

    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'TDS', 'name' => 'Area']);
    $customer = Customer::create(['code' => 'CUST-TDS', 'name' => 'Customer', 'is_active' => true]);
    $this->order = Order::create([
        'order_code' => 'ORD-TDS-1',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $this->trip->id,
        'pickup_address' => 'Pickup',
        'status' => OrderStatus::InTransit,
        'created_by' => $this->driverA->id,
    ]);
});

test('a driver who does not hold the trip cannot request a swap', function () {
    $this->trip->update(['status' => TripStatus::Delivering]);

    $this->service->requestSwap($this->trip, $this->driverB, AssignmentEndReason::ShiftHandover);
})->throws(AuthorizationException::class);

test('requesting a swap parks the trip without a driver and closes the assignment', function () {
    $this->trip->update(['status' => TripStatus::Delivering]);

    $trip = $this->service->requestSwap($this->trip, $this->driverA, AssignmentEndReason::CargoNotUnloaded, 'Kho chưa mở');

    $assignment = TripDriverAssignment::where('trip_id', $trip->id)->sole();
    expect($trip->status)->toBe(TripStatus::DriverSwap)
        ->and($trip->status_before_swap)->toBe(TripStatus::Delivering)
        ->and($trip->driver_id)->toBeNull()
        ->and($this->order->fresh()->status)->toBe(OrderStatus::DriverSwap)
        ->and($assignment->ended_at)->not->toBeNull()
        ->and($assignment->end_reason)->toBe(AssignmentEndReason::CargoNotUnloaded)
        ->and($assignment->note)->toBe('Kho chưa mở');
});

test('assigning a new driver restores progress and notifies both drivers', function () {
    $this->trip->update(['status' => TripStatus::Delivering]);
    $this->service->requestSwap($this->trip, $this->driverA, AssignmentEndReason::ShiftHandover);

    $trip = $this->service->assignDriver($this->trip->fresh(), $this->driverB);

    expect($trip->status)->toBe(TripStatus::Delivering)
        ->and($trip->driver_id)->toBe($this->driverB->id)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::InTransit)
        ->and($trip->openDriverAssignment()->first()->driver_id)->toBe($this->driverB->id)
        ->and($this->sentPushes->pluck('token')->all())->toBe(['token-b', 'token-a'])
        ->and($this->sentPushes->first()['data']['type'])->toBe('trip_driver_swapped');
});

test('orders go back to sent when the swap happened before leaving pickup', function () {
    $this->trip->update(['status' => TripStatus::ArrivedPickup]);
    $this->order->update(['status' => OrderStatus::Sent]);
    $this->service->requestSwap($this->trip, $this->driverA, AssignmentEndReason::ShiftHandover);

    $this->service->assignDriver($this->trip->fresh(), $this->driverB);

    expect($this->trip->fresh()->status)->toBe(TripStatus::ArrivedPickup)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Sent);
});

test('replacing the driver of a running trip keeps its status and records two assignments', function () {
    $this->trip->update(['status' => TripStatus::ArrivedDelivery]);

    $trip = $this->service->replaceDriver($this->trip, $this->driverB, note: 'Lái A ốm');

    $assignments = $trip->driverAssignments()->get();
    expect($trip->status)->toBe(TripStatus::ArrivedDelivery)
        ->and($assignments)->toHaveCount(2)
        ->and($assignments[0]->end_reason)->toBe(AssignmentEndReason::Reassigned)
        ->and($assignments[1]->driver_id)->toBe($this->driverB->id)
        ->and($assignments[1]->ended_at)->toBeNull();
});

test('a trip never has more than one open assignment', function () {
    $this->service->openAssignment($this->trip, $this->driverB);
    $this->service->openAssignment($this->trip, $this->driverA);

    expect(TripDriverAssignment::where('trip_id', $this->trip->id)->whereNull('ended_at')->count())->toBe(1)
        ->and($this->trip->fresh()->driver_id)->toBe($this->driverA->id);
});

test('completing a trip closes the open assignment', function () {
    $this->order->update(['status' => OrderStatus::Completed]);
    $this->trip->update(['status' => TripStatus::Delivered]);

    app(TripStateMachine::class)->complete($this->trip->fresh());

    $assignment = TripDriverAssignment::where('trip_id', $this->trip->id)->sole();
    expect($assignment->ended_at)->not->toBeNull()
        ->and($assignment->end_reason)->toBe(AssignmentEndReason::TripFinished);
});
