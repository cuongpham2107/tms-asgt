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
use App\Services\ShiftKmCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->role = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->driverA = User::factory()->create();
    $this->driverA->assignRole($this->role);

    $this->driverB = User::factory()->create();
    $this->driverB->assignRole($this->role);

    $this->vehicle = Vehicle::create([
        'plate_number' => 'SKC-TEST-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 100000,
    ]);

    $this->shiftA = DriverShift::create([
        'driver_id' => $this->driverA->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 100000,
        'start_time' => now()->subHours(3),
    ]);

    $this->shiftB = DriverShift::create([
        'driver_id' => $this->driverB->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 100100,
        'start_time' => now()->subHours(2),
    ]);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'SKC-TEST',
        'name' => 'ShiftKmCalculator Test',
    ]);

    $this->customer = Customer::create([
        'code' => 'SKC-CUST',
        'name' => 'ShiftKmCalculator Customer',
        'is_active' => true,
    ]);
});

/**
 * Chuyến bắt đầu ở driver A, đảo lái qua lại A→B→A→B (kèm 1 self-swap) rồi hoàn tất ở driver B.
 */
function buildMultiSwapTrip(
    Vehicle $vehicle,
    User $driverA,
    User $driverB,
    DriverShift $shiftA,
    DriverShift $shiftB,
    Area $area,
    Customer $customer,
    string $prefix,
): Trip {
    $trip = Trip::create([
        'trip_code' => $prefix.'-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driverB->id,
        'shift_id' => $shiftB->id,
        'status' => TripStatus::Completed,
        'start_km' => 100000,
        'end_km' => 100140,
        'total_km' => 140,
        'total_km_loaded' => 90,
        'total_km_empty' => 50,
    ]);

    $order = Order::create([
        'order_code' => $prefix.'-ORD-'.fake()->unique()->randomNumber(),
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $driverA->id,
    ]);

    $checkpoints = [
        ['started', 100000, $driverA->id, $shiftA->id],
        ['arrived_pickup', 100050, $driverA->id, $shiftA->id],
        ['left_pickup', 100050, $driverA->id, $shiftA->id],
        ['arrived_delivery', 100100, $driverA->id, $shiftA->id],
        ['driver_swap', 100100, $driverA->id, $shiftA->id],
        ['driver_swap', 100110, $driverB->id, $shiftB->id],
        ['driver_swap', 100120, $driverA->id, $shiftA->id],
        ['driver_swap', 100130, $driverB->id, $shiftB->id],
        ['completed', 100140, $driverB->id, $shiftB->id],
        ['end', 100140, $driverB->id, $shiftB->id],
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
        [$driverA->id, $shiftA->id, $driverB->id, $shiftB->id, 100100],
        [$driverB->id, $shiftB->id, $driverA->id, $shiftA->id, 100110],
        [$driverA->id, $shiftA->id, $driverB->id, $shiftB->id, 100120],
        [$driverB->id, $shiftB->id, $driverB->id, $shiftB->id, 100130],
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

    return $trip;
}

test('back-and-forth driver swaps split km across all segments', function () {
    buildMultiSwapTrip(
        $this->vehicle,
        $this->driverA,
        $this->driverB,
        $this->shiftA,
        $this->shiftB,
        $this->area,
        $this->customer,
        'SKC-SHIFT',
    );

    app(ShiftKmCalculatorService::class)->calculate($this->shiftA);
    app(ShiftKmCalculatorService::class)->calculate($this->shiftB);

    // Driver A chạy 100000→100100 (100) và 100110→100120 (10) = 110 km.
    // Có hàng: 100050→100100 (50) và 100110→100120 (10) = 60 km.
    expect((float) $this->shiftA->fresh()->total_km)->toBe(110.0);
    expect((float) $this->shiftA->fresh()->total_km_loaded)->toBe(60.0);
    expect((float) $this->shiftA->fresh()->total_km_empty)->toBe(50.0);

    // Driver B chạy 100100→100110 (10) và 100120→100140 (20) = 30 km (self-swap bị bỏ qua).
    expect((float) $this->shiftB->fresh()->total_km)->toBe(30.0);
    expect((float) $this->shiftB->fresh()->total_km_loaded)->toBe(30.0);
    expect((float) $this->shiftB->fresh()->total_km_empty)->toBe(0.0);

    // Tổng cộng hai ca phải khớp với tổng chuyến.
    expect((float) $this->shiftA->fresh()->total_km + (float) $this->shiftB->fresh()->total_km)->toBe(140.0);
});

test('trip detail API returns driver-adjusted km for back-and-forth swaps', function () {
    $trip = buildMultiSwapTrip(
        $this->vehicle,
        $this->driverA,
        $this->driverB,
        $this->shiftA,
        $this->shiftB,
        $this->area,
        $this->customer,
        'SKC-API',
    );

    Sanctum::actingAs($this->driverB);

    $response = $this->getJson("/api/driver/trips/{$trip->id}")->assertSuccessful();

    expect((float) $response->json('data.total_km'))->toBe(30.0);
    expect((float) $response->json('data.total_km_loaded'))->toBe(30.0);
    expect((float) $response->json('data.total_km_empty'))->toBe(0.0);
});
