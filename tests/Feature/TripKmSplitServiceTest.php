<?php

use App\Enums\DriverSwapReason;
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
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripKmSplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Dựng chuyến mô phỏng CD-2026-08-17-249: 3 tài xế, 2 lần đảo lái, và
 * end_km (100690) bị lỗi thời — thấp hơn km checkpoint 'completed' cuối (100700).
 *
 * @return array{0: Trip, 1: User, 2: User, 3: User, 4: DriverShift, 5: DriverShift, 6: DriverShift}
 */
function buildStaleEndKmTrip(): array
{
    $role = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $driverA = User::factory()->create();
    $driverA->assignRole($role);
    $driverB = User::factory()->create();
    $driverB->assignRole($role);
    $driverC = User::factory()->create();
    $driverC->assignRole($role);

    $vehicle = Vehicle::create([
        'plate_number' => 'SKC-STALE-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 100700,
    ]);

    $shiftA = DriverShift::create([
        'driver_id' => $driverA->id,
        'vehicle_id' => $vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 100660,
        'start_time' => now()->subHours(3),
    ]);

    $shiftB = DriverShift::create([
        'driver_id' => $driverB->id,
        'vehicle_id' => $vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 100680,
        'start_time' => now()->subHours(2),
    ]);

    $shiftC = DriverShift::create([
        'driver_id' => $driverC->id,
        'vehicle_id' => $vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 100690,
        'start_time' => now()->subHour(),
    ]);

    $area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'SKC-STALE',
        'name' => 'Stale end_km Test',
    ]);

    $customer = Customer::create([
        'code' => 'SKC-CUST',
        'name' => 'Stale end_km Customer',
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'SKC-STALE-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driverC->id,
        'shift_id' => $shiftC->id,
        'status' => TripStatus::Delivered,
        'start_km' => 100660,
        'end_km' => 100690, // lỗi thời — checkpoint cuối là 100700
        'total_km' => 30,
        'total_km_loaded' => 10,
        'total_km_empty' => 20,
    ]);

    $order = Order::create([
        'order_code' => 'SKC-STALE-ORD-'.fake()->unique()->randomNumber(),
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $driverA->id,
    ]);

    $checkpoints = [
        ['started', 100660, $driverA->id, $shiftA->id],
        ['arrived_pickup', 100680, $driverA->id, $shiftA->id],
        ['arrived_delivery', 100680, $driverA->id, $shiftA->id],
        ['driver_swap', 100680, $driverA->id, $shiftA->id],
        ['driver_swap', 100690, $driverB->id, $shiftB->id],
        ['completed', 100700, $driverC->id, $shiftC->id],
    ];

    foreach ($checkpoints as $index => [$type, $km, $driverId, $shiftId]) {
        TripCheckpoint::create([
            'trip_id' => $trip->id,
            'order_id' => $order->id,
            'driver_id' => $driverId,
            'shift_id' => $shiftId,
            'checkpoint_type' => $type,
            'occurred_at' => now()->subMinutes(60 - $index),
            'km_reading' => $km,
        ]);
    }

    $swaps = [
        [$driverA->id, $shiftA->id, $driverB->id, $shiftB->id, 100680],
        [$driverB->id, $shiftB->id, $driverC->id, $shiftC->id, 100690],
    ];

    foreach ($swaps as $index => [$fromDriver, $fromShift, $toDriver, $toShift, $handoverKm]) {
        DriverSwap::create([
            'trip_id' => $trip->id,
            'from_driver_id' => $fromDriver,
            'to_driver_id' => $toDriver,
            'from_shift_id' => $fromShift,
            'to_shift_id' => $toShift,
            'handover_km' => $handoverKm,
            'reason' => DriverSwapReason::CargoNotUnloaded,
            'created_by' => $fromDriver,
            'created_at' => now()->subMinutes(55 - $index),
        ]);
    }

    return [$trip, $driverA, $driverB, $driverC, $shiftA, $shiftB, $shiftC];
}

test('stale end_km does not zero out the last driver segment', function () {
    [$trip, , , $driverC, , , $shiftC] = buildStaleEndKmTrip();

    $segments = TripKmSplitService::segments($trip);

    expect($segments)->toHaveCount(3);

    [$start, $end, $shiftId, $driverId] = $segments[2];
    expect((float) $start)->toBe(100690.0);
    expect((float) $end)->toBe(100700.0);
    expect($shiftId)->toBe($shiftC->id);
    expect($driverId)->toBe($driverC->id);
});

test('loaded ranges extend to the last completed checkpoint km', function () {
    [$trip] = buildStaleEndKmTrip();

    expect(TripKmSplitService::loadedRanges($trip))->toBe([[100680.0, 100700.0]]);
});

test('trip detail API returns correct km for the last driver', function () {
    [$trip, , , $driverC] = buildStaleEndKmTrip();

    Sanctum::actingAs($driverC);

    $response = $this->getJson("/api/driver/trips/{$trip->id}")->assertSuccessful();

    expect((float) $response->json('data.total_km'))->toBe(10.0);
    expect((float) $response->json('data.total_km_loaded'))->toBe(10.0);
    expect((float) $response->json('data.total_km_empty'))->toBe(0.0);
});
