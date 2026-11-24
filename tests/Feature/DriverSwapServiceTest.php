<?php

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
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\DriverSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driver = User::factory()->create();
    $this->nextDriver = User::factory()->create();
    $vehicle = Vehicle::create(['plate_number' => '29C-SWAP', 'vehicle_type' => VehicleType::Normal, 'owner' => 'CLC', 'is_active' => true, 'status' => VehicleStatus::Running, 'type' => VehicleOwnerType::Company, 'current_mileage' => 1000]);
    $shift = DriverShift::create(['driver_id' => $this->driver->id, 'shift_type' => ShiftType::Full, 'start_time' => now()->subHours(3)]);
    $this->trip = Trip::create(['trip_code' => 'TRIP-SWAP', 'vehicle_id' => $vehicle->id, 'driver_id' => $this->driver->id, 'shift_id' => $shift->id, 'status' => TripStatus::Delivering, 'started_at' => now()->subHours(2), 'start_km' => 1000]);
    $area = Area::create(['code' => 'NBA', 'name' => 'NBA']);
    $customer = Customer::create(['code' => 'C', 'name' => 'C']);
    $this->running = Order::create(['order_code' => 'ASG-RUN', 'type' => OrderType::Hhhk, 'area_id' => $area->id, 'customer_id' => $customer->id, 'trip_id' => $this->trip->id, 'status' => OrderStatus::InTransit, 'created_by' => $this->driver->id]);
});

test('hand over with km closes the trip km and parks the running order', function () {
    app(DriverSwapService::class)->handOverWithKm($this->trip, 1040.0);

    $trip = $this->trip->fresh();
    expect($trip->status)->toBe(TripStatus::DriverSwap)
        ->and((float) $trip->end_km)->toBe(1040.0)
        ->and($trip->driver_id)->toBe($this->driver->id)
        ->and($trip->shift_id)->not->toBeNull()
        ->and($this->running->fresh()->status)->toBe(OrderStatus::DriverSwap);
});

test('only the trip complete api also parks assigned orders', function (bool $includeAssigned, OrderStatus $expected) {
    $this->running->update(['status' => OrderStatus::Assigned]);

    app(DriverSwapService::class)->handOverWithKm($this->trip, 1040.0, includeAssignedOrders: $includeAssigned);

    expect($this->running->fresh()->status)->toBe($expected);
})->with([
    'trip complete api' => [true, OrderStatus::DriverSwap],
    'leave vehicle' => [false, OrderStatus::Assigned],
]);

test('hand over to another driver changes the driver and detaches the old shift', function () {
    app(DriverSwapService::class)->handOverToDriver($this->trip, $this->nextDriver->id);

    $trip = $this->trip->fresh();
    expect($trip->status)->toBe(TripStatus::DriverSwap)
        ->and($trip->driver_id)->toBe($this->nextDriver->id)
        ->and($trip->shift_id)->toBeNull()
        ->and($trip->end_km)->toBeNull()
        ->and($this->running->fresh()->status)->toBe(OrderStatus::DriverSwap);
});

test('hand over on shift end detaches the shift and closes km only when given', function (?float $km, ?float $expectedEndKm) {
    app(DriverSwapService::class)->handOverOnShiftEnd($this->trip, $km);

    $trip = $this->trip->fresh();
    expect($trip->status)->toBe(TripStatus::DriverSwap)
        ->and($trip->shift_id)->toBeNull()
        ->and($trip->end_km === null ? null : (float) $trip->end_km)->toBe($expectedEndKm)
        ->and($this->running->fresh()->status)->toBe(OrderStatus::DriverSwap);
})->with([
    'with km' => [1050.0, 1050.0],
    'without km' => [0.0, null],
]);

test('a completed order is not parked by a hand over', function () {
    $this->running->update(['status' => OrderStatus::Completed]);

    app(DriverSwapService::class)->handOverToDriver($this->trip, $this->nextDriver->id);

    expect($this->running->fresh()->status)->toBe(OrderStatus::Completed);
});

test('resume puts parked orders back on the step matching the trip status', function (TripStatus $resumedAt, OrderStatus $expected) {
    app(DriverSwapService::class)->handOverToDriver($this->trip, $this->nextDriver->id);

    app(DriverSwapService::class)->resume($this->trip, $resumedAt);

    expect($this->running->fresh()->status)->toBe($expected);
})->with([
    'before pickup' => [TripStatus::Started, OrderStatus::Sent],
    'on the way to deliver' => [TripStatus::Delivering, OrderStatus::InTransit],
]);
