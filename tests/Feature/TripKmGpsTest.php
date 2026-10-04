<?php

use App\Enums\AssignmentEndReason;
use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripDriverAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use App\Services\TripKmCalculatorService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->t0 = CarbonImmutable::parse('2026-10-05 08:00:00');
    $this->vehicle = Vehicle::factory()->create();
    $this->driverA = User::factory()->create();
    $this->driverB = User::factory()->create();
    $this->area = Area::create(['type' => OrderType::Hhhk, 'code' => 'KM', 'name' => 'Km Area']);
    $this->customer = Customer::create(['code' => 'CUST-KM', 'name' => 'Km Customer', 'is_active' => true]);
});

/**
 * Tài xế chạy đều 60 km/h (1 km/phút) trong [fromMinute, toMinute], mỗi 5 giây một điểm.
 */
function kmDrive(int $driverId, int $vehicleId, CarbonImmutable $t0, int $fromMinute, int $toMinute): void
{
    for ($s = $fromMinute * 60; $s <= $toMinute * 60; $s += 5) {
        VehicleGpsPoint::create([
            'vehicle_id' => $vehicleId,
            'driver_id' => $driverId,
            'recorded_at' => $t0->addSeconds($s),
            'lat' => 10.0 + ($s / 60 * 1000) / 111195.0,
            'lng' => 106.0,
            'speed' => 60,
            'accuracy' => 8,
            'source' => VehicleGpsPoint::SOURCE_PHONE,
        ]);
    }
}

function kmTrip(Vehicle $vehicle, CarbonImmutable $t0, int $endMinute): Trip
{
    return Trip::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Completed,
        'started_at' => $t0,
        'completed_at' => $t0->addMinutes($endMinute),
    ]);
}

function kmAssign(Trip $trip, User $driver, CarbonImmutable $from, ?CarbonImmutable $to): TripDriverAssignment
{
    return TripDriverAssignment::create([
        'trip_id' => $trip->id,
        'driver_id' => $driver->id,
        'started_at' => $from,
        'ended_at' => $to,
        'end_reason' => $to ? AssignmentEndReason::TripFinished : null,
    ]);
}

function kmOrder(Trip $trip, CarbonImmutable $t0, ?int $pickupMinute, ?int $completedMinute, OrderStatus $status = OrderStatus::Completed): Order
{
    $order = Order::create([
        'order_code' => 'ORD-KM-'.fake()->unique()->numberBetween(100, 999),
        'type' => OrderType::Hhhk,
        'area_id' => test()->area->id,
        'customer_id' => test()->customer->id,
        'trip_id' => $trip->id,
        'pickup_address' => 'Pickup',
        'status' => $status,
        'created_by' => test()->driverA->id,
    ]);

    foreach ([[CheckpointType::ArrivedPickup, $pickupMinute], [CheckpointType::Completed, $completedMinute]] as [$type, $minute]) {
        if ($minute !== null) {
            TripCheckpoint::create([
                'trip_id' => $trip->id,
                'order_id' => $order->id,
                'checkpoint_type' => $type->value,
                'occurred_at' => $t0->addMinutes($minute),
            ]);
        }
    }

    return $order;
}

test('a single order trip splits loaded and empty km by checkpoint time', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 40);
    kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(40));
    $order = kmOrder($trip, $this->t0, 10, 30);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 40);

    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    expect((float) $trip->total_km)->toEqualWithDelta(40, 0.5)
        ->and((float) $trip->total_km_loaded)->toEqualWithDelta(20, 0.5)
        ->and((float) $trip->total_km_empty)->toEqualWithDelta(20, 0.5)
        ->and((float) $order->fresh()->loaded_km)->toEqualWithDelta(20, 0.5)
        ->and($trip->km_source)->toBe('phone_gps')
        ->and($trip->km_needs_review)->toBeFalse()
        ->and($trip->km_calculated_at)->not->toBeNull();
});

test('overlapping orders count shared distance once for the vehicle but fully for each order', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 50);
    kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(50));
    $orderA = kmOrder($trip, $this->t0, 10, 30);
    $orderB = kmOrder($trip, $this->t0, 20, 40);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 50);

    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    expect((float) $trip->total_km_loaded)->toEqualWithDelta(30, 0.5)
        ->and((float) $trip->total_km_empty)->toEqualWithDelta(20, 0.5)
        ->and((float) $orderA->fresh()->loaded_km)->toEqualWithDelta(20, 0.5)
        ->and((float) $orderB->fresh()->loaded_km)->toEqualWithDelta(20, 0.5);
});

test('a mid-trip driver swap splits km between the two assignments', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 50);
    $first = kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(25));
    $second = kmAssign($trip, $this->driverB, $this->t0->addMinutes(25), $this->t0->addMinutes(50));
    kmOrder($trip, $this->t0, 10, 40);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 25);
    kmDrive($this->driverB->id, $this->vehicle->id, $this->t0, 25, 50);

    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    expect((float) $first->fresh()->km + (float) $second->fresh()->km)->toEqualWithDelta((float) $trip->total_km, 0.2)
        ->and((float) $first->fresh()->km_loaded)->toEqualWithDelta(15, 0.5)
        ->and((float) $second->fresh()->km_loaded)->toEqualWithDelta(15, 0.5)
        ->and((float) $trip->total_km)->toEqualWithDelta(50, 0.5);
});

test('an order cancelled after pickup is loaded until it was cancelled', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 40);
    kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(40));
    $order = kmOrder($trip, $this->t0, 10, null, OrderStatus::Cancelled);
    $order->update(['cancelled_at' => $this->t0->addMinutes(20)]);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 40);

    app(TripKmCalculatorService::class)->calculate($trip);

    expect((float) $trip->fresh()->total_km_loaded)->toEqualWithDelta(10, 0.5);
});

test('a trip with GPS for only half of its time needs review', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 40);
    kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(40));
    kmOrder($trip, $this->t0, 5, 35);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 20);

    app(TripKmCalculatorService::class)->calculate($trip);

    expect($trip->fresh()->km_needs_review)->toBeTrue()
        ->and((float) $trip->fresh()->gps_coverage)->toBeLessThan(90);
});

test('the scheduled command settles trip km and then the shift totals', function () {
    $shift = DriverShift::create([
        'driver_id' => $this->driverA->id,
        'shift_type' => ShiftType::Full,
        'start_time' => $this->t0->subMinutes(10),
        'end_time' => $this->t0->addMinutes(60),
    ]);
    $trip = kmTrip($this->vehicle, $this->t0, 40);
    kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(40))->update(['shift_id' => $shift->id]);
    kmOrder($trip, $this->t0, 10, 30);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 50);

    $this->artisan('gps:calculate-km')->assertSuccessful();

    $shift->refresh();
    expect($trip->fresh()->km_calculated_at)->not->toBeNull()
        ->and((float) $shift->total_km)->toEqualWithDelta(50, 0.5)
        ->and((float) $shift->total_km_loaded)->toEqualWithDelta(20, 0.5)
        ->and((float) $shift->total_km_empty)->toEqualWithDelta(30, 0.5);
});

test('editing a checkpoint time on a settled trip marks it for recalculation', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 40);
    kmOrder($trip, $this->t0, 10, 30);
    $trip->update(['km_calculated_at' => now()]);

    $trip->checkpoints()->first()->update(['occurred_at' => $this->t0->addMinutes(12)]);

    expect($trip->fresh()->km_calculated_at)->toBeNull();
});
