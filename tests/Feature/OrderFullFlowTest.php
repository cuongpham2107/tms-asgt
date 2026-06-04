<?php

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\OrderCategory;
use App\Models\OrderDeliveryPoint;
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

    $this->orderCategory = OrderCategory::create([
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

/*
 * Flow:   Draft → Assigned → Sent → Started → ArrivedPickup → Delivering → ArrivedDelivery → Completed
 *         (tạo)   (gán xe)   (gửi)   (bắt đầu)   (đến lấy)    (rời lấy)    (đến giao)       (hoàn tất)
 *
 * KM kỳ vọng:
 *   total_km       = end_km - start_km        = 10100 - 10000 = 100
 *   total_km_loaded = completed.km - left_pickup.km = 10090 - 10015 = 75
 *   total_km_empty  = total_km - total_km_loaded    = 100 - 75 = 25
 */
test('full HHHK order lifecycle without swap calculates KM correctly', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $order = Order::create([
        'order_code' => 'ORD-FULL-001',
        'type' => OrderType::Hhhk,
        'order_category_id' => $this->orderCategory->id,
        'customer_id' => $this->customer->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::Sent,
        'is_return_trip' => false,
        'created_by' => $driver->id,
    ]);

    $deliveryPoint = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    Sanctum::actingAs($driver);

    // 1. Start shift
    $shiftResponse = $this->postJson('/api/driver/shifts/start', [
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full->value,
        'start_km' => 10000,
        'start_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shift = DriverShift::find($shiftResponse->json('shift.id'));

    // 2. Started checkpoint
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Started);

    // 3. Arrived pickup
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::ArrivedPickup);
    expect($deliveryPoint->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);

    // 4. Left pickup
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 10015,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Delivering);

    // 5. Arrived delivery
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 10080,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::ArrivedDelivery);

    // 6. Completed
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
    expect($deliveryPoint->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);

    // 7. End shift
    $this->postJson('/api/driver/shifts/end', [
        'end_km' => 10100,
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shift->refresh();

    // total_km = end_km - start_km = 10100 - 10000 = 100
    expect((float) $shift->total_km)->toBe(100.0);

    // loaded = completed.km (10090) - left_pickup.km (10015) = 75
    expect((float) $shift->total_km_loaded)->toBe(75.0);

    // empty = total - loaded = 100 - 75 = 25
    expect((float) $shift->total_km_empty)->toBe(25.0);
});

/*
 * Flow: Driver A đi được 1 nửa → kết thúc ca → auto driver_swap → Operator gán Driver B → hoàn tất
 *
 * KM kỳ vọng cho Driver A (in-progress):
 *   total_km_a       = end_km_a - start_km_a      = 10060 - 10000 = 60
 *   loaded_a         = end_km_a - left_pickup.km   = 10060 - 10015 = 45  (từ lúc lấy hàng đến hết ca)
 *   empty_a          = total_km_a - loaded_a       = 60 - 45 = 15         (km chạy rỗng đến điểm lấy)
 *
 * KM kỳ vọng cho Driver B (hoàn tất):
 *   total_km_b       = end_km_b - start_km_b      = 10100 - 10060 = 40
 *   loaded_b         = completed.km - start_km_b   = 10090 - 10060 = 30  (hàng đã trên xe từ đầu ca)
 *   empty_b          = total_km_b - loaded_b       = 40 - 30 = 10         (chạy sau khi giao xong)
 *
 * Kiểm tra tổng:
 *   total_trip       = 10100 - 10000 = 100
 *   loaded_total     = 45 + 30 = 75    = completed.km - left_pickup.km = 10090 - 10015 ✓
 *   empty_total      = 15 + 10 = 25    = total_trip - loaded_total        ✓
 */
test('driver swap mid-delivery correctly splits KM between two drivers', function () {
    $driverA = User::factory()->create();
    $driverA->assignRole($this->driverRole);

    $driverB = User::factory()->create();
    $driverB->assignRole($this->driverRole);

    $order = Order::create([
        'order_code' => 'ORD-SWAP-001',
        'type' => OrderType::Hhhk,
        'order_category_id' => $this->orderCategory->id,
        'customer_id' => $this->customer->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driverA->id,
        'status' => OrderStatus::Sent,
        'is_return_trip' => false,
        'created_by' => $driverA->id,
    ]);

    $deliveryPoint = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    // ============================================
    // PHASE 1: Driver A starts, picks up cargo
    // ============================================
    Sanctum::actingAs($driverA);

    $shiftAResponse = $this->postJson('/api/driver/shifts/start', [
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full->value,
        'start_km' => 10000,
        'start_time' => now()->toIso8601String(),
    ])->assertSuccessful();
    $shiftA = DriverShift::find($shiftAResponse->json('shift.id'));

    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shiftA->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shiftA->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shiftA->id,
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 10015,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Delivering);

    // Driver A ends shift mid-delivery → auto driver_swap
    $this->postJson('/api/driver/shifts/end', [
        'end_km' => 10060,
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shiftA->refresh();
    expect($order->fresh()->status)->toBe(OrderStatus::DriverSwap);
    expect($this->vehicle->fresh()->current_driver_id)->toBeNull();

    // Driver A's KM: total=60, loaded=45 (in-progress: end_km - left_pickup), empty=15
    expect((float) $shiftA->total_km)->toBe(60.0);
    expect((float) $shiftA->total_km_loaded)->toBe(45.0);
    expect((float) $shiftA->total_km_empty)->toBe(15.0);

    // ============================================
    // PHASE 2: Operator reassigns Driver B
    // ============================================
    $order->update([
        'driver_id' => $driverB->id,
        'status' => OrderStatus::Assigned,
    ]);

    $order->driverSwaps()->create([
        'from_driver_id' => $driverA->id,
        'to_driver_id' => $driverB->id,
        'from_shift_id' => $shiftA->id,
        'to_shift_id' => null,
        'handover_km' => 10060,
        'reason' => DriverSwapReason::ShiftHandover,
        'created_by' => $driverA->id,
    ]);

    // ============================================
    // PHASE 3: Driver B completes delivery
    // ============================================
    Sanctum::actingAs($driverB);

    $shiftBResponse = $this->postJson('/api/driver/shifts/start', [
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full->value,
        'start_km' => 10060,
        'start_time' => now()->toIso8601String(),
    ])->assertSuccessful();
    $shiftB = DriverShift::find($shiftBResponse->json('shift.id'));

    // Driver B starts, then goes to delivery point and completes
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shiftB->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shiftB->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 10080,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::ArrivedDelivery);

    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shiftB->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);

    // Driver B ends shift (drives a bit more after completing)
    $this->postJson('/api/driver/shifts/end', [
        'end_km' => 10100,
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shiftB->refresh();

    // Driver B's KM: total=40, loaded=30 (cargo on board: completed.km - start_km_b), empty=10
    expect((float) $shiftB->total_km)->toBe(40.0);
    expect((float) $shiftB->total_km_loaded)->toBe(30.0);
    expect((float) $shiftB->total_km_empty)->toBe(10.0);

    // Cumulative: A(45+15) + B(30+10) = 75+25 = 100 = full trip
    $totalLoadedKm = (float) $shiftA->total_km_loaded + (float) $shiftB->total_km_loaded;
    $totalEmptyKm = (float) $shiftA->total_km_empty + (float) $shiftB->total_km_empty;

    expect($totalLoadedKm)->toEqual(75.0);
    expect($totalEmptyKm)->toEqual(25.0);
});
