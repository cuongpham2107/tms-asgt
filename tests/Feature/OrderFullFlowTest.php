<?php

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderDeliveryPointStatus;
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
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'NORTH',
        'name' => 'North',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Customer 001',
        'is_active' => true,
    ]);

    $this->vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);
});

function createTripAndOrder(Vehicle $vehicle, User $driver, Area $area, Customer $customer, string $orderCode): array
{
    $trip = Trip::create([
        'trip_code' => 'TRIP-'.$orderCode,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]);

    $order = Order::create([
        'order_code' => $orderCode,
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'is_return_trip' => false,
        'created_by' => $driver->id,
    ]);

    $deliveryPoint = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    return [$trip, $order, $deliveryPoint];
}

function postCheckpoint(User $driver, Trip $trip, string $type, ?int $kmReading = null, ?OrderDeliveryPoint $deliveryPoint = null, ?Order $order = null): array
{
    $data = [
        'checkpoint_type' => $type,
        'occurred_at' => now()->toIso8601String(),
    ];

    if ($kmReading !== null) {
        $data['km_reading'] = $kmReading;
    }

    if (in_array($type, [CheckpointType::ArrivedDelivery->value, CheckpointType::Completed->value], true)) {
        $data['delivery_point_id'] = $deliveryPoint->id;
        $data['order_id'] = $order->id;
    }

    if ($type === CheckpointType::ArrivedPickup->value && $deliveryPoint !== null) {
        $data['delivery_point_id'] = $deliveryPoint->id;
    }

    Sanctum::actingAs($driver);

    return [$data, test()->postJson("/api/driver/trips/{$trip->id}/checkpoints", $data)];
}

/*
 * Flow:   Sent → Started → ArrivedPickup → Delivering → ArrivedDelivery → Completed
 *         (gửi) (bắt đầu)  (đến lấy)      (rời lấy)    (đến giao)        (hoàn tất)
 *
 * KM kỳ vọng:
 *   total_km       = end_km - start_km                  = 10100 - 10000 = 100
 *   total_km_loaded = completed.km - arrived_pickup.km  = 10090 - 10010 = 80
 *   total_km_empty  = total_km - total_km_loaded        = 100 - 80 = 20
 *
 * Ghi chú:
 *   - started.km tự động lấy từ vehicle.current_mileage (ko nhập)
 *   - Tài xế chỉ nhập km tại arrived_pickup và completed
 */
test('full HHHK order lifecycle without swap calculates KM correctly', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    [$trip, $order, $deliveryPoint] = createTripAndOrder($this->vehicle, $driver, $this->area, $this->customer, 'ORD-FULL-001');

    Sanctum::actingAs($driver);

    // Set vehicle mileage trước khi bắt đầu ca
    $this->vehicle->update(['current_mileage' => 10000]);

    // 1. Start shift
    $shiftResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $this->vehicle->id,
    ])->assertSuccessful();

    $shift = DriverShift::find($shiftResponse->json('shift.id'));

    // 2. Started checkpoint (ko nhập km, tự lấy từ vehicle.current_mileage)
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->trip->status)->toBe(TripStatus::Started);

    // 3. Arrived pickup
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->trip->status)->toBe(TripStatus::ArrivedPickup);
    expect($deliveryPoint->fresh()->status)->toBe(OrderDeliveryPointStatus::Pending);

    // 4. Left pickup
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 10015,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->trip->status)->toBe(TripStatus::Delivering);

    // 5. Arrived delivery
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'order_id' => $order->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 10080,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->trip->status)->toBe(TripStatus::ArrivedDelivery);

    // 6. Completed
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'order_id' => $order->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
    expect($deliveryPoint->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);

    // 7. Complete trip (manual)
    $this->postJson("/api/driver/trips/{$trip->id}/complete", [
        'end_km' => 10100,
    ])->assertSuccessful();

    // 8. End shift
    $this->postJson("/api/driver/shifts/{$shift->id}/end-vehicle", [
        'km_reading' => 10100,
    ])->assertSuccessful();

    $this->postJson('/api/driver/shifts/end', [
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shift->refresh();
    expect($shift->end_time)->not->toBeNull();
});

/*
 * Flow: Driver A đi được 1 nửa → kết thúc ca → auto driver_swap → Operator gán Driver B → hoàn tất
 */
test('driver swap mid-delivery hands trip over to second driver', function () {
    $driverA = User::factory()->create();
    $driverA->assignRole($this->driverRole);

    $driverB = User::factory()->create();
    $driverB->assignRole($this->driverRole);

    [$trip, $order, $deliveryPoint] = createTripAndOrder($this->vehicle, $driverA, $this->area, $this->customer, 'ORD-SWAP-001');

    // ============================================
    // PHASE 1: Driver A starts, picks up cargo
    // ============================================
    Sanctum::actingAs($driverA);

    $this->vehicle->update(['current_mileage' => 10000]);

    $shiftAResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $this->vehicle->id,
    ])->assertSuccessful();
    $shiftA = DriverShift::find($shiftAResponse->json('shift.id'));

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 10015,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($order->fresh()->trip->status)->toBe(TripStatus::Delivering);

    // Driver A ends shift mid-delivery → auto driver_swap
    $this->postJson("/api/driver/shifts/{$shiftA->id}/end-vehicle", [
        'km_reading' => 10060,
    ])->assertSuccessful();

    $this->postJson('/api/driver/shifts/end', [
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shiftA->refresh();
    expect($trip->fresh()->status)->toBe(TripStatus::DriverSwap);

    // ============================================
    // PHASE 2: Operator reassigns Driver B
    // ============================================
    // Resume where Driver A left off (already left pickup)
    $trip->update([
        'driver_id' => $driverB->id,
        'status' => TripStatus::Delivering,
    ]);

    DriverSwap::create([
        'trip_id' => $trip->id,
        'from_driver_id' => $driverA->id,
        'to_driver_id' => $driverB->id,
        'from_shift_id' => $shiftA->id,
        'to_shift_id' => null,
        'reason' => DriverSwapReason::ShiftHandover,
        'created_by' => $driverA->id,
    ]);

    // ============================================
    // PHASE 3: Driver B completes delivery
    // ============================================
    Sanctum::actingAs($driverB);

    $shiftBResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $this->vehicle->id,
    ])->assertSuccessful();
    $shiftB = DriverShift::find($shiftBResponse->json('shift.id'));

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'order_id' => $order->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 10080,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->trip->status)->toBe(TripStatus::ArrivedDelivery);

    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'order_id' => $order->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);

    // Complete trip (manual) before ending shift
    $this->postJson("/api/driver/trips/{$trip->id}/complete", [
        'end_km' => 10100,
    ])->assertSuccessful();

    // Driver B ends shift
    $this->postJson("/api/driver/shifts/{$shiftB->id}/end-vehicle", [
        'km_reading' => 10100,
    ])->assertSuccessful();

    $this->postJson('/api/driver/shifts/end', [
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shiftB->refresh();
    expect($shiftB->end_time)->not->toBeNull();
});

/*
 * Flow: Driver A làm 2 đơn — hoàn tất đơn 1 → hết giờ, kết thúc ca → auto driver_swap cho chuyến còn lại
 *       → Điều hành swap Driver B → Driver B hoàn tất
 */
test('driver with 2 orders runs out of shift time triggers swap via trip DriverSwapAction', function () {
    $adminUser = User::factory()->create();
    $adminUser->assignRole('driver');

    $driverA = User::factory()->create();
    $driverA->assignRole($this->driverRole);

    $driverB = User::factory()->create();
    $driverB->assignRole($this->driverRole);

    // ── Trip 1 + Order 1 (hoàn tất bởi Driver A) ──────────────────────
    $trip1 = Trip::create([
        'trip_code' => 'TRIP-ORD-A1-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driverA->id,
    ]);
    $order1 = Order::create([
        'order_code' => 'ORD-A1-001',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip1->id,
        'status' => OrderStatus::Sent,
        'is_return_trip' => false,
        'created_by' => $driverA->id,
    ]);
    $dp1 = OrderDeliveryPoint::create([
        'order_id' => $order1->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    // ── Trip 2 + Order 2 (bắt đầu bởi Driver A, swap → Driver B hoàn tất) ─
    $trip2 = Trip::create([
        'trip_code' => 'TRIP-ORD-A2-002',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driverA->id,
    ]);
    $order2 = Order::create([
        'order_code' => 'ORD-A2-002',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip2->id,
        'status' => OrderStatus::Sent,
        'is_return_trip' => false,
        'created_by' => $driverA->id,
    ]);
    $dp2 = OrderDeliveryPoint::create([
        'order_id' => $order2->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    // ============================================
    // PHASE 1: Driver A hoàn tất Order 1
    // ============================================
    Sanctum::actingAs($driverA);

    $this->vehicle->update(['current_mileage' => 10000]);

    $shiftAResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $this->vehicle->id,
    ])->assertSuccessful();
    $shiftA = DriverShift::find($shiftAResponse->json('shift.id'));

    // Order 1: started
    $this->postJson("/api/driver/trips/{$trip1->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 1: arrived_pickup (km=10010)
    $this->postJson("/api/driver/trips/{$trip1->id}/checkpoints", [
        'delivery_point_id' => $dp1->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 1: left_pickup
    $this->postJson("/api/driver/trips/{$trip1->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 10015,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 1: arrived_delivery
    $this->postJson("/api/driver/trips/{$trip1->id}/checkpoints", [
        'order_id' => $order1->id,
        'delivery_point_id' => $dp1->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 10025,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 1: completed (km=10030)
    $this->postJson("/api/driver/trips/{$trip1->id}/checkpoints", [
        'order_id' => $order1->id,
        'delivery_point_id' => $dp1->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10030,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order1->fresh()->status)->toBe(OrderStatus::Completed);

    // Complete trip1 manually before starting trip2
    $this->postJson("/api/driver/trips/{$trip1->id}/complete", [
        'end_km' => 10030,
    ])->assertSuccessful();

    // ============================================
    // PHASE 2: Driver A bắt đầu Order 2, hết ca
    // ============================================
    // Order 2: started
    $this->postJson("/api/driver/trips/{$trip2->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 2: arrived_pickup (km=10040)
    $this->postJson("/api/driver/trips/{$trip2->id}/checkpoints", [
        'delivery_point_id' => $dp2->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10040,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 2: left_pickup
    $this->postJson("/api/driver/trips/{$trip2->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 10045,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order2->fresh()->trip->status)->toBe(TripStatus::Delivering);

    // Driver A hết ca → end shift → auto DriverSwap on Trip 2
    $this->postJson("/api/driver/shifts/{$shiftA->id}/end-vehicle", [
        'km_reading' => 10060,
    ])->assertSuccessful();

    $this->postJson('/api/driver/shifts/end', [
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shiftA->refresh();
    expect($trip2->fresh()->status)->toBe(TripStatus::DriverSwap);
    expect($order1->fresh()->status)->toBe(OrderStatus::Completed);

    // ============================================
    // PHASE 3: Driver B vào ca → Điều hành swap
    // ============================================
    Sanctum::actingAs($driverB);

    $shiftBResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $this->vehicle->id,
    ])->assertSuccessful();
    $shiftB = DriverShift::find($shiftBResponse->json('shift.id'));

    // Simulate driver swap (same logic as Trips DriverSwapAction): tạo swap và gán lại driver cho trip
    DriverSwap::create([
        'trip_id' => $trip2->id,
        'from_driver_id' => $driverA->id,
        'to_driver_id' => $driverB->id,
        'from_shift_id' => $shiftA->id,
        'to_shift_id' => $shiftB->id,
        'reason' => DriverSwapReason::ShiftHandover,
        'note' => 'Hết ca, bàn giao cho tài xế B',
        'created_by' => $adminUser->id,
    ]);

    $trip2->update([
        'driver_id' => $driverB->id,
        'shift_id' => $shiftB->id,
        'status' => TripStatus::Delivering,
    ]);

    // ============================================
    // PHASE 4: Driver B hoàn tất Order 2
    // ============================================

    // Order 2: arrived_delivery
    $this->postJson("/api/driver/trips/{$trip2->id}/checkpoints", [
        'order_id' => $order2->id,
        'delivery_point_id' => $dp2->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 10080,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order2->fresh()->trip->status)->toBe(TripStatus::ArrivedDelivery);

    // Order 2: completed
    $this->postJson("/api/driver/trips/{$trip2->id}/checkpoints", [
        'order_id' => $order2->id,
        'delivery_point_id' => $dp2->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order2->fresh()->status)->toBe(OrderStatus::Completed);

    // Complete trip (manual) before ending shift
    $this->postJson("/api/driver/trips/{$trip2->id}/complete", [
        'end_km' => 10100,
    ])->assertSuccessful();

    // Driver B kết thúc ca
    $this->postJson("/api/driver/shifts/{$shiftB->id}/end-vehicle", [
        'km_reading' => 10100,
    ])->assertSuccessful();

    $this->postJson('/api/driver/shifts/end', [
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shiftB->refresh();
    expect($shiftB->end_time)->not->toBeNull();
});
