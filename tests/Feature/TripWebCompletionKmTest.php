<?php

use App\Enums\CheckpointType;
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
use App\Services\TripKmAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->role = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->driverA = User::factory()->create(['name' => 'Driver A']);
    $this->driverA->assignRole($this->role);

    $this->driverB = User::factory()->create(['name' => 'Driver B']);
    $this->driverB->assignRole($this->role);

    $this->vehicle = Vehicle::create([
        'plate_number' => 'WEB-TEST-001',
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
        'start_time' => now()->subHours(4),
    ]);

    $this->shiftB = DriverShift::create([
        'driver_id' => $this->driverB->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_km' => 100050,
        'start_time' => now()->subHours(2),
    ]);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'WEB-AREA',
        'name' => 'Web Area',
    ]);

    $this->customer = Customer::create([
        'code' => 'WEB-CUST',
        'name' => 'Web Customer',
        'is_active' => true,
    ]);
});

/**
 * Helper to run the same recalculation the trip edit action performs.
 */
function callTripsTableRecalculateKm(Trip $trip): void
{
    app(TripKmAdjustmentService::class)->recalculateAfterEdit($trip->refresh());
}

test('Scenario 1: Driver phone breaks mid-trip, admin completes trip on web and recalculates km', function () {
    // 1. Trip created and started by Driver A
    $trip = Trip::create([
        'trip_code' => 'TRIP-BROKEN-PHONE-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'status' => TripStatus::Delivering,
        'start_km' => 100000,
        'started_at' => now()->subHours(3),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-BP-01',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->driverA->id,
    ]);

    // Checkpoint started and arrived_pickup done on phone
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->subHours(3),
        'km_reading' => 100000,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'occurred_at' => now()->subHours(2)->subMinutes(30),
        'km_reading' => 100020,
    ]);

    // Driver phone breaks. Admin enters remaining checkpoints on web:
    // Notice: web checkpoints may not have shift_id set initially
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => null, // created from web
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'occurred_at' => now()->subHours(1),
        'km_reading' => 100080,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => null, // created from web
        'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => now()->subMinutes(30),
        'km_reading' => 100100,
    ]);

    $trip->update([
        'status' => TripStatus::Completed,
        'completed_at' => now()->subMinutes(30),
    ]);

    // Admin clicks "Tính lại Km" on TripsTable
    callTripsTableRecalculateKm($trip);

    $trip->refresh();
    expect((float) $trip->end_km)->toBe(100100.0);
    expect((float) $trip->total_km)->toBe(100.0);
    expect((float) $trip->total_km_loaded)->toBe(80.0);
    expect((float) $trip->total_km_empty)->toBe(20.0);

    // Driver A shift must have 100 km (80 loaded, 20 empty)
    $this->shiftA->refresh();
    expect((float) $this->shiftA->total_km)->toBe(100.0);
    expect((float) $this->shiftA->total_km_loaded)->toBe(80.0);
    expect((float) $this->shiftA->total_km_empty)->toBe(20.0);

    // Mobile API for Driver A
    Sanctum::actingAs($this->driverA);
    $stats = $this->getJson('/api/driver/trips/stats')->assertSuccessful();
    expect((float) $stats->json('data.total_km'))->toBe(100.0);
    expect((float) $stats->json('data.total_km_loaded'))->toBe(80.0);

    $tripDetail = $this->getJson("/api/driver/trips/{$trip->id}")->assertSuccessful();
    expect((float) $tripDetail->json('data.total_km'))->toBe(100.0);
});

test('Scenario 2: Driver swap mid-trip, completed on web, both drivers get their respective km', function () {
    // 1. Driver A starts trip at 100000
    $trip = Trip::create([
        'trip_code' => 'TRIP-SWAP-WEB-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'status' => TripStatus::Started,
        'start_km' => 100000,
        'started_at' => now()->subHours(3),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-SWAP-01',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->driverA->id,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->subHours(3),
        'km_reading' => 100000,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'occurred_at' => now()->subHours(2)->subMinutes(30),
        'km_reading' => 100020,
    ]);

    // Driver swap at 100050 km
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => CheckpointType::DriverSwap->value,
        'occurred_at' => now()->subHours(2),
        'km_reading' => 100050,
    ]);

    // DriverSwap record created (note: to_shift_id might be null if driver B shift wasn't known)
    DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $this->driverA->id,
        'to_driver_id' => $this->driverB->id,
        'from_shift_id' => $this->shiftA->id,
        'to_shift_id' => null, // common case when new driver has not opened shift or not linked
        'handover_km' => 100050,
        'reason' => DriverSwapReason::CargoNotUnloaded,
        'created_by' => $this->driverA->id,
    ]);

    // Handover sets trip driver to B, status to DriverSwap, end_km was temporarily set to handover_km
    $trip->update([
        'driver_id' => $this->driverB->id,
        'shift_id' => null,
        'status' => TripStatus::DriverSwap,
        'end_km' => 100050,
    ]);

    // Now on web, dispatcher edits trip, adds checkpoints for Driver B up to km 100120
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverB->id,
        'shift_id' => null,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'occurred_at' => now()->subHours(1),
        'km_reading' => 100100,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverB->id,
        'shift_id' => null,
        'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => now()->subMinutes(30),
        'km_reading' => 100120,
    ]);

    $trip->update([
        'status' => TripStatus::Completed,
        'completed_at' => now()->subMinutes(30),
    ]);

    // Admin clicks "Tính lại Km" on TripsTable
    callTripsTableRecalculateKm($trip);

    $trip->refresh();
    // Total trip km: 100120 - 100000 = 120 km
    expect((float) $trip->end_km)->toBe(100120.0);
    expect((float) $trip->total_km)->toBe(120.0);

    // Driver A ran 100000 to 100050 = 50 km (loaded 100020 to 100050 = 30 km, empty = 20 km)
    $this->shiftA->refresh();
    expect((float) $this->shiftA->total_km)->toBe(50.0);
    expect((float) $this->shiftA->total_km_loaded)->toBe(30.0);
    expect((float) $this->shiftA->total_km_empty)->toBe(20.0);

    // Driver B ran 100050 to 100120 = 70 km (loaded 100050 to 100120 = 70 km, empty = 0 km)
    $this->shiftB->refresh();
    expect((float) $this->shiftB->total_km)->toBe(70.0);
    expect((float) $this->shiftB->total_km_loaded)->toBe(70.0);
    expect((float) $this->shiftB->total_km_empty)->toBe(0.0);

    // Mobile API stats for Driver A
    Sanctum::actingAs($this->driverA);
    $statsA = $this->getJson('/api/driver/trips/stats')->assertSuccessful();
    expect((float) $statsA->json('data.total_km'))->toBe(50.0);

    // Mobile API stats for Driver B
    Sanctum::actingAs($this->driverB);
    $statsB = $this->getJson('/api/driver/trips/stats')->assertSuccessful();
    expect((float) $statsB->json('data.total_km'))->toBe(70.0);

    // Trip detail for Driver B should return adjusted km (70 km)
    $tripDetailB = $this->getJson("/api/driver/trips/{$trip->id}")->assertSuccessful();
    expect((float) $tripDetailB->json('data.total_km'))->toBe(70.0);

    // Trip detail for Driver A should return adjusted km (50 km)
    Sanctum::actingAs($this->driverA);
    $tripDetailA = $this->getJson("/api/driver/trips/{$trip->id}")->assertSuccessful();
    expect((float) $tripDetailA->json('data.total_km'))->toBe(50.0);
});

test('Scenario 3: Driver swap checkpoint exists without DriverSwap row, recalculateKm detects and attributes km to both drivers', function () {
    // Trip like #378: mobile EndHandler created driver_swap checkpoint, but no DriverSwap row was in DB
    $trip = Trip::create([
        'trip_code' => 'TRIP-NO-SWAP-ROW-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driverB->id,
        'status' => TripStatus::Completed,
        'start_km' => 100000,
        'end_km' => 100050, // stuck at handover km
        'started_at' => now()->subHours(3),
        'completed_at' => now()->subMinutes(30),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-NOSWAP-01',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->driverA->id,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->subHours(3),
        'km_reading' => 100000,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverA->id,
        'shift_id' => $this->shiftA->id,
        'checkpoint_type' => CheckpointType::DriverSwap->value,
        'occurred_at' => now()->subHours(2),
        'km_reading' => 100050,
    ]);

    // Web added checkpoints for Driver B
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $this->driverB->id,
        'shift_id' => null,
        'checkpoint_type' => CheckpointType::Completed->value,
        'occurred_at' => now()->subMinutes(30),
        'km_reading' => 100150,
    ]);

    // Admin clicks "Tính lại Km" on TripsTable
    callTripsTableRecalculateKm($trip);

    $trip->refresh();
    expect((float) $trip->end_km)->toBe(100150.0);
    expect((float) $trip->total_km)->toBe(150.0);

    // Driver A shift gets 50 km
    $this->shiftA->refresh();
    expect((float) $this->shiftA->total_km)->toBe(50.0);

    // Driver B shift gets 100 km
    $this->shiftB->refresh();
    expect((float) $this->shiftB->total_km)->toBe(100.0);
});
