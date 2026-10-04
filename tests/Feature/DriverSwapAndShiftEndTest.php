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
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripKmCalculatorService;
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

function endMakeTrip(DriverShift $shift, Vehicle $vehicle, User $driver, ?int $startKm = null): Trip
{
    return Trip::create([
        'trip_code' => 'TRIP-END-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Started,
        'started_at' => now()->subHours(3),
    ]);
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

function endMakeCheckpoint(Trip $trip, Order $order, string $type, ?int $kmReading = null, ?DriverShift $shift = null, ?Vehicle $vehicle = null): TripCheckpoint
{
    return TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $trip->driver_id,
        'shift_id' => $shift?->id ?? $trip->shift_id,
        'vehicle_id' => $vehicle?->id ?? $trip->vehicle_id,
        'checkpoint_type' => $type,
        'occurred_at' => now(),
    ]);
}

// === TEST 1: Regression — trip đơn giản, loaded/empty đúng như cũ ===
test('simple trip with one order calculates loaded and empty km correctly', function () {
    $shift = endMakeShift($this->driver);
    $trip = endMakeTrip($shift, $this->vehicle, $this->driver, 10000);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);

    endMakeCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, 10010, $shift, $this->vehicle);
    endMakeCheckpoint($trip, $order, CheckpointType::Completed->value, 10090, $shift, $this->vehicle);

    $trip->update(['end_km' => 10090]);
    app(TripKmCalculatorService::class)->calculate($trip);

    $trip->refresh();
    expect((float) $trip->total_km)->toBe(90.0);
    expect((float) $trip->total_km_loaded)->toBe(80.0);
    expect((float) $trip->total_km_empty)->toBe(10.0);
})->skip('Km calculation will be reimplemented in P6 with GPS');

// === TEST 2: Bug 1 — km lang thang sau khi hoàn thành đơn cuối ===
test('wandering km after last order goes to shift empty km', function () {
    //
})->skip('Km calculation will be reimplemented in P6 with GPS');

// === TEST 3: Ca tiếp theo nhận đúng start_km ===
test('next shift starts at end checkpoint km', function () {
    //
})->skip('Km calculation will be reimplemented in P6 with GPS');

// === TEST 4: Bug 2 — đổi xe giữa ca ===
test('vehicle swap mid-shift calculates correct segmented km', function () {
    //
})->skip('Km calculation will be reimplemented in P6 with GPS');

// === TEST 5: End Shift khi đang có trip, chưa có checkpoint 'end' → reject ===
test('end shift without end checkpoint is rejected', function () {
    $shift = endMakeShift($this->driver);
    // Create a trip so the gate applies (no-trip shifts skip the gate)
    endMakeTrip($shift, $this->vehicle, $this->driver, 10000);

    $response = $this->postJson('/api/driver/shifts/end', []);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Cần kết thúc xe trước khi kết thúc ca.');
});

// === TEST 6: Rời xe khi đang có trip chưa hoàn thành → driver_swap ===
test('end vehicle with active incomplete trip triggers driver swap', function () {
    $shift = endMakeShift($this->driver);

    $trip = endMakeTrip($shift, $this->vehicle, $this->driver);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);

    endMakeCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, null, $shift, $this->vehicle);

    $this->postJson("/api/driver/shifts/{$shift->id}/end-vehicle", [
        'km_reading' => 10060,
    ])->assertSuccessful();

    $trip->refresh();
    expect($trip->status)->toBe(TripStatus::DriverSwap);

    // Verify checkpoint type is DriverSwap, not End
    $checkpoint = TripCheckpoint::where('trip_id', $trip->id)
        ->where('checkpoint_type', CheckpointType::DriverSwap->value)
        ->first();
    expect($checkpoint)->not->toBeNull();
    expect($checkpoint->order_id)->not->toBeNull();
});

// === TEST 7: Nhập km_reading không validate current_mileage nữa ===
test('end vehicle accepts km_reading without validating against current mileage', function () {
    $shift = endMakeShift($this->driver);
    $trip = endMakeTrip($shift, $this->vehicle, $this->driver);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);
    $this->vehicle->current_mileage = 10050;
    $this->vehicle->save();

    $response = $this->postJson("/api/driver/shifts/{$shift->id}/end-vehicle", [
        'km_reading' => 10000,
    ]);

    $response->assertSuccessful();
});

// === TEST 8: End shift succeeds when driver has pending trips with assigned orders ===
test('end shift succeeds when driver has pending trips with assigned orders', function () {
    $shift = endMakeShift($this->driver);
    $trip1 = endMakeTrip($shift, $this->vehicle, $this->driver);
    $order1 = endMakeOrder($trip1, $this->driver, $this->area, $this->customer);
    $trip1->update(['status' => TripStatus::Completed]);
    $order1->update(['status' => OrderStatus::Completed]);

    // Checkpoint 'end' for the shift
    TripCheckpoint::create([
        'shift_id' => $shift->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::End->value,
        'occurred_at' => now(),
    ]);

    // Another trip in Pending status with only Assigned order
    $trip2 = Trip::create([
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
        'trip_id' => $trip2->id,
        'created_by' => $this->driver->id,
    ]);

    $response = $this->postJson('/api/driver/shifts/end', []);

    $response->assertSuccessful();
    $shift->refresh();
    expect($shift->end_time)->not->toBeNull();
    // Trip 2 should remain pending and not be touched
    expect($trip2->fresh()->status)->toBe(TripStatus::Pending);
});

// === TEST 9: End shift is rejected when driver has in-progress trip with sent orders ===
test('end shift is rejected when driver has in-progress trip with sent orders', function () {
    $shift = endMakeShift($this->driver);
    $trip = endMakeTrip($shift, $this->vehicle, $this->driver);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);
    $trip->update(['status' => TripStatus::Started]);
    $order->update(['status' => OrderStatus::Sent]);

    TripCheckpoint::create([
        'shift_id' => $shift->id,
        'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::End->value,
        'occurred_at' => now(),
    ]);

    $response = $this->postJson('/api/driver/shifts/end', []);

    $response->assertStatus(422);
    $response->assertJsonPath('message', fn ($msg) => str_contains($msg, 'chuyến đang hoạt động'));
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
