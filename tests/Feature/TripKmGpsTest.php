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
use App\Services\Trip\TripLegService;
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

test('a swap before pickup leaves the handover driver fully empty and gives the receiver empty-to-pickup then loaded', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 50);
    $first = kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(5));
    $second = kmAssign($trip, $this->driverB, $this->t0->addMinutes(5), $this->t0->addMinutes(50));
    kmOrder($trip, $this->t0, 15, 40);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 5);
    kmDrive($this->driverB->id, $this->vehicle->id, $this->t0, 5, 50);

    app(TripKmCalculatorService::class)->calculate($trip);

    // A giao xe trước khi lấy hàng: toàn bộ rỗng. B: rỗng tới kho [5,15] + có hàng [15,40] + rỗng về [40,50].
    expect((float) $first->fresh()->km_loaded)->toEqualWithDelta(0, 0.5)
        ->and((float) $first->fresh()->km_empty)->toEqualWithDelta(5, 0.5)
        ->and((float) $second->fresh()->km_loaded)->toEqualWithDelta(25, 0.5)
        ->and((float) $second->fresh()->km_empty)->toEqualWithDelta(20, 0.5);
});

test('a total-only km adjustment is spread across each driver assignment', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 50);
    $first = kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(25));
    $second = kmAssign($trip, $this->driverB, $this->t0->addMinutes(25), $this->t0->addMinutes(50));
    kmOrder($trip, $this->t0, 10, 40);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 25);
    kmDrive($this->driverB->id, $this->vehicle->id, $this->t0, 25, 50);
    app(TripKmCalculatorService::class)->calculate($trip);
    // GPS: mỗi lượt 25 km (tổng 50), có hàng 15 km mỗi lượt.

    // Điều hành chỉnh thẳng tổng lên 60 km, có hàng 30 km.
    app(TripLegService::class)->applyTotalAdjustment($trip->fresh(), 60, 30, 'Bù km kẹt xe', 1);

    $trip->refresh();
    // Số điều chỉnh phải lan xuống từng lượt lái (app tài xế & km ca đọc từ đây).
    expect((float) $trip->km_adjusted)->toEqualWithDelta(60, 0.01)
        ->and((float) $first->fresh()->km)->toEqualWithDelta(30, 0.5)
        ->and((float) $second->fresh()->km)->toEqualWithDelta(30, 0.5)
        ->and((float) $first->fresh()->km_loaded)->toEqualWithDelta(15, 0.5)
        ->and((float) $first->fresh()->km + (float) $second->fresh()->km)->toEqualWithDelta(60, 0.2);
});

test('a long GPS gap still counts the straight-line distance instead of dropping it', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 40);
    kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(40));
    kmOrder($trip, $this->t0, 5, 35);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 10);
    // Mất sóng 20 phút (10'->30', vượt osrm_max_gap 15'), xe vẫn đi ~20 km.
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 30, 40);

    app(TripKmCalculatorService::class)->calculate($trip);

    // 10 (đoạn 1) + ~20 (đường thẳng qua khoảng mất sóng) + 10 (đoạn 2) ≈ 40 km, không bị về 20.
    expect((float) $trip->fresh()->total_km)->toEqualWithDelta(40, 1.0);
});

test('an empty run trip counts all distance as empty and does not need review', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 30);
    $trip->update(['is_empty_run' => true]);
    kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(30));
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 30);

    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    expect((float) $trip->total_km)->toEqualWithDelta(30, 0.5)
        ->and((float) $trip->total_km_loaded)->toEqualWithDelta(0, 0.5)
        ->and((float) $trip->total_km_empty)->toEqualWithDelta(30, 0.5)
        ->and($trip->km_needs_review)->toBeFalse();
});

test('a swap after delivery gives the handover driver loaded plus empty and the receiver an empty return', function () {
    $trip = kmTrip($this->vehicle, $this->t0, 50);
    $first = kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(42));
    $second = kmAssign($trip, $this->driverB, $this->t0->addMinutes(42), $this->t0->addMinutes(50));
    kmOrder($trip, $this->t0, 10, 40);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 42);
    kmDrive($this->driverB->id, $this->vehicle->id, $this->t0, 42, 50);

    app(TripKmCalculatorService::class)->calculate($trip);

    // A: rỗng [0,10] + có hàng [10,40] + rỗng [40,42]. B nhận xe sau khi giao xong: rỗng về bãi [42,50].
    expect((float) $first->fresh()->km_loaded)->toEqualWithDelta(30, 0.5)
        ->and((float) $first->fresh()->km_empty)->toEqualWithDelta(12, 0.5)
        ->and((float) $second->fresh()->km_loaded)->toEqualWithDelta(0, 0.5)
        ->and((float) $second->fresh()->km_empty)->toEqualWithDelta(8, 0.5);
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
    $this->travelTo($this->t0->addHours(3));

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

test('trips are not settled until the upload grace period has passed', function () {
    $this->travelTo($this->t0->addMinutes(45));
    $trip = kmTrip($this->vehicle, $this->t0, 40);

    $this->artisan('gps:calculate-km')->assertSuccessful();

    expect($trip->fresh()->km_calculated_at)->toBeNull();
});

test('driver swap before arrived pickup counts all previous km as empty', function () {
    // Chuyến 50 phút. Lấy hàng ở phút 10, hoàn thành ở phút 40.
    // Đảo lái ở phút 5 (TRƯỚC KHI ĐẾN LẤY HÀNG).
    $trip = kmTrip($this->vehicle, $this->t0, 50);
    $first = kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(5));
    $second = kmAssign($trip, $this->driverB, $this->t0->addMinutes(5), $this->t0->addMinutes(50));
    kmOrder($trip, $this->t0, 10, 40);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 5);
    kmDrive($this->driverB->id, $this->vehicle->id, $this->t0, 5, 50);

    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    // Tài xế A: chạy từ phút 0 đến phút 5 => hoàn toàn là xe rỗng (0 km có hàng)
    expect((float) $first->fresh()->km)->toEqualWithDelta(5, 0.5)
        ->and((float) $first->fresh()->km_loaded)->toBe(0.0)
        ->and((float) $first->fresh()->km_empty)->toEqualWithDelta(5, 0.5);

    // Tài xế B: chạy từ phút 5 đến 50 => có hàng từ phút 10 đến 40 (30 km), không hàng (5-10 & 40-50: 15 km)
    expect((float) $second->fresh()->km)->toEqualWithDelta(45, 0.5)
        ->and((float) $second->fresh()->km_loaded)->toEqualWithDelta(30, 0.5)
        ->and((float) $second->fresh()->km_empty)->toEqualWithDelta(15, 0.5);
});

test('driver swap after completed counts all subsequent km as empty', function () {
    // Chuyến 50 phút. Lấy hàng ở phút 10, hoàn thành ở phút 40.
    // Đảo lái ở phút 45 (SAU KHI HOÀN THÀNH GIAO HÀNG).
    $trip = kmTrip($this->vehicle, $this->t0, 50);
    $first = kmAssign($trip, $this->driverA, $this->t0, $this->t0->addMinutes(45));
    $second = kmAssign($trip, $this->driverB, $this->t0->addMinutes(45), $this->t0->addMinutes(50));
    kmOrder($trip, $this->t0, 10, 40);
    kmDrive($this->driverA->id, $this->vehicle->id, $this->t0, 0, 45);
    kmDrive($this->driverB->id, $this->vehicle->id, $this->t0, 45, 50);

    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    // Tài xế A: chạy từ phút 0 đến 45 => có hàng 30 km (10 -> 40), không hàng 15 km (0 -> 10 và 40 -> 45)
    expect((float) $first->fresh()->km)->toEqualWithDelta(45, 0.5)
        ->and((float) $first->fresh()->km_loaded)->toEqualWithDelta(30, 0.5)
        ->and((float) $first->fresh()->km_empty)->toEqualWithDelta(15, 0.5);

    // Tài xế B: chạy từ phút 45 đến 50 => sau khi đã hoàn thành giao hàng, hoàn toàn là xe rỗng (0 km có hàng)
    expect((float) $second->fresh()->km)->toEqualWithDelta(5, 0.5)
        ->and((float) $second->fresh()->km_loaded)->toBe(0.0)
        ->and((float) $second->fresh()->km_empty)->toEqualWithDelta(5, 0.5);
});
