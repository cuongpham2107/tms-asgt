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
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
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

/*
 * Flow:   Draft → Assigned → Sent → Started → ArrivedPickup → Delivering → ArrivedDelivery → Completed
 *         (tạo)   (gán xe)   (gửi)   (bắt đầu)   (đến lấy)    (rời lấy)    (đến giao)       (hoàn tất)
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

    $order = Order::create([
        'order_code' => 'ORD-FULL-001',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
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

    // loaded = completed.km (10090) - arrived_pickup.km (10010) = 80
    expect((float) $shift->total_km_loaded)->toBe(80.0);

    // empty = total - loaded = 100 - 80 = 20
    expect((float) $shift->total_km_empty)->toBe(20.0);
});

/*
 * Flow: Driver A đi được 1 nửa → kết thúc ca → auto driver_swap → Operator gán Driver B → hoàn tất
 *
 * KM kỳ vọng cho Driver A (in-progress):
 *   total_km_a       = end_km_a - start_km_a          = 10060 - 10000 = 60
 *   loaded_a         = end_km_a - arrived_pickup.km   = 10060 - 10010 = 50
 *   empty_a          = total_km_a - loaded_a           = 60 - 50 = 10
 *
 * KM kỳ vọng cho Driver B (hoàn tất):
 *   total_km_b       = end_km_b - start_km_b          = 10100 - 10060 = 40
 *   loaded_b         = completed.km - segment.start_km = 10090 - 10060 = 30
 *   empty_b          = total_km_b - loaded_b           = 40 - 30 = 10
 *
 * Kiểm tra tổng:
 *   total_trip       = 10100 - 10000 = 100
 *   loaded_total     = 50 + 30 = 80
 *   empty_total      = 10 + 10 = 20 = total_trip - loaded_total ✓
 *
 * Ghi chú:
 *   - started.km tự động lấy từ vehicle.current_mileage (ko nhập)
 *   - Driver A có arrived_pickup nhưng ko completed → loaded = segment.end_km - arrived_pickup
 *   - Driver B có completed nhưng ko arrived_pickup → loaded = completed - segment.start_km
 */
test('driver swap mid-delivery correctly splits KM between two drivers', function () {
    $driverA = User::factory()->create();
    $driverA->assignRole($this->driverRole);

    $driverB = User::factory()->create();
    $driverB->assignRole($this->driverRole);

    $order = Order::create([
        'order_code' => 'ORD-SWAP-001',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
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

    // Set vehicle mileage — started checkpoint sẽ tự lấy
    $this->vehicle->update(['current_mileage' => 10000]);

    $shiftAResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $this->vehicle->id,
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

    // Driver A's KM: total=60, loaded=50 (end_km - arrived_pickup.km = 10060-10010), empty=10
    expect((float) $shiftA->total_km)->toBe(60.0);
    expect((float) $shiftA->total_km_loaded)->toBe(50.0);
    expect((float) $shiftA->total_km_empty)->toBe(10.0);

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

    // Vehicle mileage đã là 10060 từ Driver A's end — started sẽ tự lấy
    $shiftBResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
        'vehicle_id' => $this->vehicle->id,
    ])->assertSuccessful();
    $shiftB = DriverShift::find($shiftBResponse->json('shift.id'));

    // Driver B starts (ko nhập km, tự lấy từ vehicle = 10060)
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

    // Driver B's KM: total=40, loaded=30 (completed.km - segment.start_km = 10090-10060), empty=10
    expect((float) $shiftB->total_km)->toBe(40.0);
    expect((float) $shiftB->total_km_loaded)->toBe(30.0);
    expect((float) $shiftB->total_km_empty)->toBe(10.0);

    // Cumulative: A(50+10) + B(30+10) = 80+20 = 100 = full trip
    $totalLoadedKm = (float) $shiftA->total_km_loaded + (float) $shiftB->total_km_loaded;
    $totalEmptyKm = (float) $shiftA->total_km_empty + (float) $shiftB->total_km_empty;

    expect($totalLoadedKm)->toEqual(80.0);
    expect($totalEmptyKm)->toEqual(20.0);
});

/*
 * Flow: Driver A làm 2 đơn — hoàn tất đơn 1 → hết giờ, kết thúc ca → auto driver_swap đơn 2
 *       → Điều hành dùng DriverSwapAction gán Driver B (đang có ca) → Driver B hoàn tất đơn 2
 *
 * KM kỳ vọng:
 *   Driver A (hoàn tất đơn 1 + đơn 2 dở dang):
 *     total_km_a       = 10060 - 10000 = 60
 *     loaded_a         = (10030-10010) + (10060-10040) = 20 + 20 = 40
 *     empty_a          = 60 - 40 = 20
 *
 *   Driver B (hoàn tất đơn 2):
 *     total_km_b       = 10100 - 10060 = 40
 *     loaded_b         = 10090 - 10060 = 30 (completed - segment.start_km)
 *     empty_b          = 40 - 30 = 10
 *
 *   Cumulative: loaded=70, empty=30, total=100
 */
test('driver with 2 orders runs out of shift time triggers swap via DriverSwapAction', function () {
    $adminUser = User::factory()->create();
    $adminUser->assignRole('driver');

    $driverA = User::factory()->create();
    $driverA->assignRole($this->driverRole);

    $driverB = User::factory()->create();
    $driverB->assignRole($this->driverRole);

    // ── Order 1 (hoàn tất bởi Driver A) ──────────────────────────────
    $order1 = Order::create([
        'order_code' => 'ORD-A1-001',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driverA->id,
        'status' => OrderStatus::Sent,
        'is_return_trip' => false,
        'created_by' => $driverA->id,
    ]);

    $dp1 = OrderDeliveryPoint::create([
        'order_id' => $order1->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    // ── Order 2 (bắt đầu bởi Driver A, swap → Driver B hoàn tất) ─────
    $order2 = Order::create([
        'order_code' => 'ORD-A2-002',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driverA->id,
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
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order1->id,
        'shift_id' => $shiftA->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 1: arrived_pickup (km=10010)
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order1->id,
        'shift_id' => $shiftA->id,
        'delivery_point_id' => $dp1->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order1->fresh()->status)->toBe(OrderStatus::ArrivedPickup);

    // Order 1: left_pickup
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order1->id,
        'shift_id' => $shiftA->id,
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 10015,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 1: arrived_delivery
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order1->id,
        'shift_id' => $shiftA->id,
        'delivery_point_id' => $dp1->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 10025,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 1: completed (km=10030)
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order1->id,
        'shift_id' => $shiftA->id,
        'delivery_point_id' => $dp1->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10030,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order1->fresh()->status)->toBe(OrderStatus::Completed);

    // ============================================
    // PHASE 2: Driver A bắt đầu Order 2, hết ca
    // ============================================

    // Order 2: started (vehicle mileage = 10030 sau order 1)
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order2->id,
        'shift_id' => $shiftA->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 2: arrived_pickup (km=10040)
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order2->id,
        'shift_id' => $shiftA->id,
        'delivery_point_id' => $dp2->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10040,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 2: left_pickup
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order2->id,
        'shift_id' => $shiftA->id,
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 10045,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order2->fresh()->status)->toBe(OrderStatus::Delivering);

    // Driver A hết ca → end shift → auto DriverSwap on Order 2
    $this->postJson('/api/driver/shifts/end', [
        'end_km' => 10060,
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shiftA->refresh();
    expect($order2->fresh()->status)->toBe(OrderStatus::DriverSwap);
    expect($order1->fresh()->status)->toBe(OrderStatus::Completed);

    // Driver A's KM: total=60, loaded=40, empty=20
    expect((float) $shiftA->total_km)->toBe(60.0);
    expect((float) $shiftA->total_km_loaded)->toBe(40.0);
    expect((float) $shiftA->total_km_empty)->toBe(20.0);

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

    // Simulate DriverSwapAction::make() do điều hành thực hiện
    DriverSwap::create([
        'order_id' => $order2->id,
        'from_driver_id' => $driverA->id,
        'to_driver_id' => $driverB->id,
        'from_shift_id' => $shiftA->id,
        'to_shift_id' => $shiftB->id,
        'handover_km' => 10060,
        'reason' => DriverSwapReason::ShiftHandover,
        'note' => 'Hết ca, bàn giao cho tài xế B',
        'created_by' => $adminUser->id,
    ]);

    $order2->update([
        'driver_id' => $driverB->id,
        'status' => OrderStatus::DriverSwap,
    ]);

    // Driver B được gán shift_id cho order
    $order2->update(['shift_id' => $shiftB->id]);

    // ============================================
    // PHASE 4: Driver B hoàn tất Order 2
    // ============================================

    // Vehicle mileage = 10060 từ khi Driver A kết thúc
    // Order 2: started (ko nhập km, tự lấy từ xe = 10060)
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order2->id,
        'shift_id' => $shiftB->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order 2: arrived_delivery
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order2->id,
        'shift_id' => $shiftB->id,
        'delivery_point_id' => $dp2->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 10080,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order2->fresh()->status)->toBe(OrderStatus::ArrivedDelivery);

    // Order 2: completed
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order2->id,
        'shift_id' => $shiftB->id,
        'delivery_point_id' => $dp2->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order2->fresh()->status)->toBe(OrderStatus::Completed);

    // Driver B kết thúc ca
    $this->postJson('/api/driver/shifts/end', [
        'end_km' => 10100,
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shiftB->refresh();

    // Driver B's KM: total=40, loaded=30, empty=10
    expect((float) $shiftB->total_km)->toBe(40.0);
    expect((float) $shiftB->total_km_loaded)->toBe(30.0);
    expect((float) $shiftB->total_km_empty)->toBe(10.0);

    // Cumulative
    expect((float) $shiftA->total_km_loaded + (float) $shiftB->total_km_loaded)->toEqual(70.0);
    expect((float) $shiftA->total_km_empty + (float) $shiftB->total_km_empty)->toEqual(30.0);
});
