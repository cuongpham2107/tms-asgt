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
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripDriverAssignment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->role = Role::create(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($this->role);

    $this->vehicle = Vehicle::create([
        'plate_number' => 'END-TEST-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 10000,
    ]);

    $this->vehicle2 = Vehicle::create([
        'plate_number' => 'END-TEST-002',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 50000,
    ]);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'END-TEST',
        'name' => 'End Test Area',
    ]);

    $this->customer = Customer::create([
        'code' => 'END-CUST',
        'name' => 'End Customer',
        'is_active' => true,
    ]);

    Sanctum::actingAs($this->driver);
});

function endMakeShift(User $driver): DriverShift
{
    return DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHours(4),
    ]);
}

function endMakeTrip(DriverShift $shift, Vehicle $vehicle, User $driver): Trip
{
    $trip = Trip::create([
        'trip_code' => 'TRIP-END-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Started,
        'started_at' => now()->subHours(3),
    ]);

    TripDriverAssignment::create([
        'trip_id' => $trip->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'started_at' => now()->subHours(3),
    ]);

    return $trip;
}

function endMakeOrder(Trip $trip, User $driver, $area, $customer): Order
{
    return Order::create([
        'order_code' => 'ORD-END-'.fake()->unique()->randomNumber(),
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'created_by' => $driver->id,
    ]);
}

// === Đảo lái: chỉ tài xế đang giữ chuyến được xin ===
test('swap is forbidden for a driver who does not hold the trip', function () {
    $otherDriver = User::factory()->create();
    $otherDriver->assignRole($this->role);
    $shift = endMakeShift($otherDriver);
    $trip = endMakeTrip($shift, $this->vehicle, $otherDriver);

    $this->postJson("/api/driver/trips/{$trip->id}/swap", ['reason' => 'shift_handover'])
        ->assertForbidden();

    expect($trip->fresh()->status)->toBe(TripStatus::Started)
        ->and($trip->fresh()->driver_id)->toBe($otherDriver->id);
});

test('swap rejects an invalid reason', function (string $reason) {
    $shift = endMakeShift($this->driver);
    $trip = endMakeTrip($shift, $this->vehicle, $this->driver);

    $this->postJson("/api/driver/trips/{$trip->id}/swap", ['reason' => $reason])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
})->with(['reassigned', 'nonsense']);

test('swap moves trip to driver_swap and releases the driver', function () {
    $shift = endMakeShift($this->driver);
    $trip = endMakeTrip($shift, $this->vehicle, $this->driver);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);
    $trip->update(['status' => TripStatus::ArrivedPickup]);

    $this->postJson("/api/driver/trips/{$trip->id}/swap", [
        'reason' => 'cargo_not_unloaded',
        'note' => 'Kho đóng cửa',
    ])->assertSuccessful();

    $trip->refresh();
    expect($trip->status)->toBe(TripStatus::DriverSwap)
        ->and($trip->status_before_swap)->toBe(TripStatus::ArrivedPickup)
        ->and($trip->driver_id)->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::DriverSwap);

    $assignment = TripDriverAssignment::where('trip_id', $trip->id)->sole();
    expect($assignment->ended_at)->not->toBeNull()
        ->and($assignment->end_reason?->value)->toBe('cargo_not_unloaded');

    expect(TripCheckpoint::where('trip_id', $trip->id)
        ->where('checkpoint_type', CheckpointType::DriverSwap->value)
        ->exists())->toBeTrue();
});

// === Kết thúc ca: không cần checkpoint end, tự giải phóng chuyến ===
test('end shift with no trips succeeds', function () {
    $shift = endMakeShift($this->driver);

    $this->postJson('/api/driver/shifts/end', [])->assertSuccessful();

    expect($shift->fresh()->end_time)->not->toBeNull();
});

test('end shift auto-swaps an in-progress trip', function () {
    $shift = endMakeShift($this->driver);
    $trip = endMakeTrip($shift, $this->vehicle, $this->driver);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);

    $this->postJson('/api/driver/shifts/end', [])->assertSuccessful();

    $trip->refresh();
    expect($shift->fresh()->end_time)->not->toBeNull()
        ->and($trip->status)->toBe(TripStatus::DriverSwap)
        ->and($trip->driver_id)->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::DriverSwap)
        ->and(TripDriverAssignment::where('trip_id', $trip->id)->sole()->end_reason?->value)->toBe('shift_handover');
});

test('end shift completes a delivered trip', function () {
    $shift = endMakeShift($this->driver);
    $trip = endMakeTrip($shift, $this->vehicle, $this->driver);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);
    $order->update(['status' => OrderStatus::Completed]);
    $trip->update(['status' => TripStatus::Delivered]);

    $this->postJson('/api/driver/shifts/end', [])->assertSuccessful();

    expect($trip->fresh()->status)->toBe(TripStatus::Completed)
        ->and($shift->fresh()->end_time)->not->toBeNull();
});

test('end shift unassigns the driver from pending trips', function () {
    $shift = endMakeShift($this->driver);

    $pendingTrip = Trip::create([
        'trip_code' => 'TRIP-PENDING-1',
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Pending,
        'shift_id' => null,
    ]);
    Order::create([
        'order_code' => 'ASG-PENDING-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Assigned,
        'trip_id' => $pendingTrip->id,
        'created_by' => $this->driver->id,
    ]);

    $this->postJson('/api/driver/shifts/end', [])->assertSuccessful();

    $pendingTrip->refresh();
    expect($shift->fresh()->end_time)->not->toBeNull()
        ->and($pendingTrip->status)->toBe(TripStatus::Pending)
        ->and($pendingTrip->driver_id)->toBeNull();
});

// === TEST 10: Driver can start a new trip when having another pending trip ===
test('driver can start a new trip when having another pending trip', function () {
    $shift = endMakeShift($this->driver);

    // Trip 1: Pending with Assigned order
    $pendingTrip = Trip::create([
        'trip_code' => 'TRIP-PENDING-2',
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'status' => TripStatus::Pending,
        'shift_id' => null,
    ]);
    Order::create([
        'order_code' => 'ASG-PENDING-2',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Assigned,
        'trip_id' => $pendingTrip->id,
        'created_by' => $this->driver->id,
    ]);

    // Trip 2: Pending with Sent order to be started
    $activeTrip = Trip::create([
        'trip_code' => 'TRIP-ACTIVE-1',
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle2->id,
        'status' => TripStatus::Pending,
        'shift_id' => null,
    ]);
    $order2 = Order::create([
        'order_code' => 'ASG-ACTIVE-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Sent,
        'trip_id' => $activeTrip->id,
        'created_by' => $this->driver->id,
    ]);

    $response = $this->postJson("/api/driver/trips/{$activeTrip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'km_reading' => 50000,
        'occurred_at' => now()->toIso8601String(),
    ]);

    $response->assertSuccessful();
    expect($activeTrip->fresh()->status)->toBe(TripStatus::Started);
    expect($pendingTrip->fresh()->status)->toBe(TripStatus::Pending);
});
