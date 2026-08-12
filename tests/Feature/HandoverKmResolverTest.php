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
use App\Services\HandoverKmResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->role = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->driverA = User::factory()->create();
    $this->driverA->assignRole($this->role);

    $this->driverB = User::factory()->create();
    $this->driverB->assignRole($this->role);

    $this->vehicle = Vehicle::create([
        'plate_number' => 'HK-TEST-001',
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
        'start_km' => 100640,
        'start_time' => now()->subHours(2),
    ]);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'HK-TEST',
        'name' => 'HandoverKm Test',
    ]);

    $this->customer = Customer::create([
        'code' => 'HK-CUST',
        'name' => 'HK Customer',
        'is_active' => true,
    ]);
});

test('resolve returns correct handover km when multiple swaps from same shift', function () {
    // Trip starts at 100,420
    $trip = Trip::create([
        'trip_code' => 'HK-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'status' => TripStatus::Started,
        'started_at' => now()->subHours(3),
        'start_km' => 100420,
    ]);

    $order = Order::create([
        'order_code' => 'HK-ORD-'.fake()->unique()->randomNumber(),
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->driverA->id,
    ]);

    // Swap 1: driver A hands over to driver B at KM 100,640
    $swap1 = DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $this->driverA->id,
        'to_driver_id' => $this->driverB->id,
        'from_shift_id' => $this->shiftA->id,
        'to_shift_id' => $this->shiftB->id,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $this->driverA->id,
        'created_at' => now()->subHours(2)->subMinutes(5),
    ]);

    // driver_swap checkpoint from shift A (first handover)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => 'driver_swap',
        'occurred_at' => now()->subHours(2)->subMinutes(5),
        'km_reading' => 100640,
    ]);

    // Swap 2: driver B hands back to driver A at KM 100,650
    $swap2 = DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $this->driverB->id,
        'to_driver_id' => $this->driverA->id,
        'from_shift_id' => $this->shiftB->id,
        'to_shift_id' => $this->shiftA->id,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $this->driverB->id,
        'created_at' => now()->subHours(1)->subMinutes(5),
    ]);

    // driver_swap checkpoint from shift B (handover from B)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverB->id,
        'shift_id' => $this->shiftB->id,
        'checkpoint_type' => 'driver_swap',
        'occurred_at' => now()->subHours(1)->subMinutes(5),
        'km_reading' => 100650,
    ]);

    // Swap 3: driver A does another internal handover at 100,660 (same driver, same shift)
    $swap3 = DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $this->driverA->id,
        'to_driver_id' => $this->driverA->id,
        'from_shift_id' => $this->shiftA->id,
        'to_shift_id' => $this->shiftA->id,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $this->driverA->id,
        'created_at' => now()->subMinutes(30),
    ]);

    // driver_swap checkpoint from shift A (second handover from A)
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => 'driver_swap',
        'occurred_at' => now()->subMinutes(30),
        'km_reading' => 100660,
    ]);

    // Record final checkpoints for driver A after receiving the trip back
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => 'completed',
        'occurred_at' => now()->subMinutes(10),
        'km_reading' => 100680,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => 'end',
        'occurred_at' => now(),
        'km_reading' => 100690,
    ]);

    $trip->update(['end_km' => 100690]);

    // Verify HandoverKmResolver returns correct values
    // Swap 1 (driver A → driver B, from shift A): km = 100,640 (first driver_swap of shift A)
    $km1 = HandoverKmResolver::resolve($trip, $swap1);
    expect($km1)->toBe(100640.0);

    // Swap 2 (driver B → driver A, from shift B): km = 100,650
    $km2 = HandoverKmResolver::resolve($trip, $swap2);
    expect($km2)->toBe(100650.0);

    // Swap 3 (driver A internal, from shift A): km = 100,660 (second driver_swap of shift A)
    $km3 = HandoverKmResolver::resolve($trip, $swap3);
    expect($km3)->toBe(100660.0);
});

test('resolve returns correct handover km when swap has direct handover_km value', function () {
    $trip = Trip::create([
        'trip_code' => 'HK-DIRECT-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
        'start_km' => 50000,
    ]);

    $swap = DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $this->driverA->id,
        'to_driver_id' => $this->driverB->id,
        'from_shift_id' => $this->shiftA->id,
        'to_shift_id' => $this->shiftB->id,
        'handover_km' => 50500,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $this->driverA->id,
        'created_at' => now(),
    ]);

    // Direct handover_km value should be used without fallback
    $km = HandoverKmResolver::resolve($trip, $swap);
    expect($km)->toBe(50500.0);
});

test('resolve returns 0 when no handover_km and no matching checkpoints', function () {
    $trip = Trip::create([
        'trip_code' => 'HK-NONE-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
        'start_km' => 50000,
    ]);

    $swap = DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $this->driverA->id,
        'to_driver_id' => $this->driverB->id,
        'from_shift_id' => $this->shiftA->id,
        'to_shift_id' => $this->shiftB->id,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $this->driverA->id,
        'created_at' => now(),
    ]);

    // No handover_km, no driver_swap checkpoints → should return 0
    $km = HandoverKmResolver::resolve($trip, $swap);
    expect($km)->toBe(0.0);
});
