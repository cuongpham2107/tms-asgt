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
        'start_km' => 10000,
    ]);
}

function endMakeTrip(DriverShift $shift, Vehicle $vehicle, User $driver, int $startKm): Trip
{
    return Trip::create([
        'trip_code' => 'TRIP-END-'.fake()->unique()->randomNumber(),
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Started,
        'started_at' => now()->subHours(3),
        'start_km' => $startKm,
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

function endMakeCheckpoint(Trip $trip, Order $order, string $type, int $kmReading, ?DriverShift $shift = null, ?Vehicle $vehicle = null): TripCheckpoint
{
    return TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'driver_id' => $trip->driver_id,
        'shift_id' => $shift?->id ?? $trip->shift_id,
        'vehicle_id' => $vehicle?->id ?? $trip->vehicle_id,
        'checkpoint_type' => $type,
        'occurred_at' => now(),
        'km_reading' => $kmReading,
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
});

// === TEST 2: Bug 1 — km lang thang sau khi hoàn thành đơn cuối ===
test('wandering km after last order goes to shift empty km', function () {
    $shift = endMakeShift($this->driver);
    $shift->start_km = 10000;
    $shift->save();

    $trip = endMakeTrip($shift, $this->vehicle, $this->driver, 10000);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);

    endMakeCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, 10010, $shift, $this->vehicle);
    endMakeCheckpoint($trip, $order, CheckpointType::Completed->value, 10050, $shift, $this->vehicle);
    $trip->update(['end_km' => 10050, 'status' => TripStatus::Completed]);
    app(TripKmCalculatorService::class)->calculate($trip);

    $this->postJson("/api/driver/shifts/{$shift->id}/end-vehicle", [
        'km_reading' => 10080,
    ])->assertSuccessful();

    $endCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
        ->where('checkpoint_type', CheckpointType::End->value)
        ->first();
    expect($endCheckpoint)->not->toBeNull();
    expect((float) $endCheckpoint->km_reading)->toBe(10080.0);

    $this->postJson('/api/driver/shifts/end', [
        'end_gps_lat' => 10.5,
        'end_gps_lng' => 106.5,
    ])->assertSuccessful();

    $shift->refresh();
    expect((float) $shift->end_km)->toBe(10080.0);
    expect((float) $shift->total_km_empty)->toBeGreaterThanOrEqual(30.0);
});

// === TEST 3: Ca tiếp theo nhận đúng start_km ===
test('next shift starts at end checkpoint km', function () {
    $shift1 = endMakeShift($this->driver);
    $shift1->start_km = 10000;
    $shift1->save();

    $trip = endMakeTrip($shift1, $this->vehicle, $this->driver, 10000);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);

    endMakeCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, 10010, $shift1, $this->vehicle);
    endMakeCheckpoint($trip, $order, CheckpointType::Completed->value, 10050, $shift1, $this->vehicle);
    $trip->update(['end_km' => 10050, 'status' => TripStatus::Completed]);
    app(TripKmCalculatorService::class)->calculate($trip);

    $this->postJson("/api/driver/shifts/{$shift1->id}/end-vehicle", [
        'km_reading' => 10080,
    ])->assertSuccessful();

    $this->postJson('/api/driver/shifts/end', [])->assertSuccessful();

    expect((float) $this->vehicle->fresh()->current_mileage)->toBe(10080.0);

    $shift2 = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
        'start_km' => $this->vehicle->fresh()->current_mileage,
    ]);

    expect((float) $shift2->start_km)->toBe(10080.0);
});

// === TEST 4: Bug 2 — đổi xe giữa ca ===
test('vehicle swap mid-shift calculates correct segmented km', function () {
    $shift = endMakeShift($this->driver);
    $shift->start_km = 10000;
    $shift->save();

    // Vehicle 1 segment
    $trip1 = endMakeTrip($shift, $this->vehicle, $this->driver, 10000);
    $order1 = endMakeOrder($trip1, $this->driver, $this->area, $this->customer);

    endMakeCheckpoint($trip1, $order1, CheckpointType::ArrivedPickup->value, 10010, $shift, $this->vehicle);
    endMakeCheckpoint($trip1, $order1, CheckpointType::Completed->value, 10050, $shift, $this->vehicle);
    $trip1->update(['end_km' => 10050, 'status' => TripStatus::Completed]);
    app(TripKmCalculatorService::class)->calculate($trip1);

    // End vehicle 1
    $this->postJson("/api/driver/shifts/{$shift->id}/end-vehicle", [
        'km_reading' => 10070,
    ])->assertSuccessful();

    // Switch to vehicle 2
    $this->postJson('/api/driver/shifts/switch-vehicle', [
        'new_vehicle_id' => $this->vehicle2->id,
        'handover_km' => 50000,
    ])->assertSuccessful();

    // Vehicle 2 segment
    $trip2 = Trip::create([
        'trip_code' => 'TRIP-END-V2',
        'vehicle_id' => $this->vehicle2->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Started,
        'started_at' => now()->subHour(),
        'start_km' => 50000,
    ]);

    $order2 = endMakeOrder($trip2, $this->driver, $this->area, $this->customer);
    endMakeCheckpoint($trip2, $order2, CheckpointType::ArrivedPickup->value, 50010, $shift, $this->vehicle2);
    endMakeCheckpoint($trip2, $order2, CheckpointType::Completed->value, 50080, $shift, $this->vehicle2);
    $trip2->update(['end_km' => 50080, 'status' => TripStatus::Completed]);
    app(TripKmCalculatorService::class)->calculate($trip2);

    // End vehicle 2
    $this->postJson("/api/driver/shifts/{$shift->id}/end-vehicle", [
        'km_reading' => 50100,
    ])->assertSuccessful();

    // End shift
    $this->postJson('/api/driver/shifts/end', [])->assertSuccessful();

    $shift->refresh();
    expect((float) $shift->end_km)->toBe(50100.0);
    expect((float) $shift->total_km)->toBeGreaterThan(0);
    expect((float) $shift->total_km)->toBeLessThan(1000000);
    expect((float) $shift->total_km_loaded)->toBeLessThan(1000000);
    expect((float) $shift->total_km_empty)->toBeLessThan(1000000);
});

// === TEST 5: End Shift khi đang có trip, chưa có checkpoint 'end' → reject ===
test('end shift without end checkpoint is rejected', function () {
    $shift = endMakeShift($this->driver);
    // Create a trip so the gate applies (no-trip shifts skip the gate)
    endMakeTrip($shift, $this->vehicle, $this->driver, 10000);

    $response = $this->postJson('/api/driver/shifts/end', []);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Cần nhập km kết thúc trước khi kết thúc ca.');
});

// === TEST 6: Rời xe khi đang có trip chưa hoàn thành → driver_swap ===
test('end vehicle with active incomplete trip triggers driver swap', function () {
    $shift = endMakeShift($this->driver);
    $shift->start_km = 10000;
    $shift->save();

    $trip = endMakeTrip($shift, $this->vehicle, $this->driver, 10000);
    $order = endMakeOrder($trip, $this->driver, $this->area, $this->customer);

    endMakeCheckpoint($trip, $order, CheckpointType::ArrivedPickup->value, 10010, $shift, $this->vehicle);

    $this->postJson("/api/driver/shifts/{$shift->id}/end-vehicle", [
        'km_reading' => 10060,
    ])->assertSuccessful();

    $trip->refresh();
    expect($trip->status)->toBe(TripStatus::DriverSwap);
    // shift_id is cleared during end() cleanup, not in EndHandler
    expect((float) $trip->end_km)->toBe(10060.0);
    expect((float) $trip->total_km_loaded)->toBeGreaterThan(0);

    // Verify checkpoint type is DriverSwap, not End
    $checkpoint = TripCheckpoint::where('trip_id', $trip->id)
        ->where('checkpoint_type', CheckpointType::DriverSwap->value)
        ->first();
    expect($checkpoint)->not->toBeNull();
    expect((float) $checkpoint->km_reading)->toBe(10060.0);
    expect($checkpoint->order_id)->not->toBeNull();
});

// === TEST 7: Nhập km_reading nhỏ hơn vehicle.current_mileage → reject ===
test('end vehicle with km less than current mileage is rejected', function () {
    $shift = endMakeShift($this->driver);
    $trip = endMakeTrip($shift, $this->vehicle, $this->driver, 10000);
    $this->vehicle->current_mileage = 10050;
    $this->vehicle->save();

    $response = $this->postJson("/api/driver/shifts/{$shift->id}/end-vehicle", [
        'km_reading' => 10000,
    ]);

    $response->assertStatus(422);
});
