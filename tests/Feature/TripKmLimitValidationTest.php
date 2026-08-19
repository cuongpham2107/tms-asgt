<?php

use App\Enums\CheckpointType;
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
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\TripKmLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'TEST-KM',
        'name' => 'Test KM Area',
    ]);
    $this->customer = Customer::create([
        'code' => 'CUST-KM',
        'name' => 'Test Customer KM',
        'is_active' => true,
    ]);
    $this->vehicle = Vehicle::create([
        'plate_number' => '51A-99999',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 50000,
    ]);
    $this->driver = User::factory()->create(['name' => 'Tài Xế A']);
    $this->driver->assignRole($this->driverRole);
    $this->vehicle->update(['current_driver_id' => $this->driver->id]);

    $this->driverShift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
        'start_km' => 50000,
    ]);

    $this->pickupLocation = Location::create([
        'code' => 'PICKUP-KM',
        'name' => 'Kho Điểm Nhận',
        'lat' => 10.818889,
        'lng' => 106.651944,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);
    $this->deliveryLocation = Location::create([
        'code' => 'DELIVERY-KM-1',
        'name' => 'Điểm Giao 1',
        'lat' => 10.764722,
        'lng' => 106.781944,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-KM-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'start_km' => 50000,
    ]);

    $this->order = Order::create([
        'order_code' => 'ORD-KM-001',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Kho Nhận Hàng',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $this->dp1 = OrderDeliveryPoint::create([
        'order_id' => $this->order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'Địa chỉ giao 1',
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    Sanctum::actingAs($this->driver);
});

test('Chặng 1 -> 2 (Started -> ArrivedPickup): cho phép tối đa +600km', function () {
    // 1. Nhập lùi km (49,900 < 50,000) -> Bị từ chối
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 49900,
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422)
        ->assertJsonPath('errors.km_reading.0', fn ($msg) => str_contains($msg, 'không được nhỏ hơn'));

    // 2. Nhập vượt quá 600km (50,605 > 50,000 + 600) -> Bị từ chối
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50605,
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422)
        ->assertJsonPath('errors.km_reading.0', fn ($msg) => str_contains($msg, 'vượt quá giới hạn cho phép (+600 km)'));

    // 3. Nhập hợp lệ trong khoảng 600km (50,550 <= 50,000 + 600) -> Thành công
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50550,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect((float) $this->vehicle->fresh()->current_mileage)->toBe(50550.0);
});

test('Chặng 2 -> 3 (ArrivedPickup -> LeftPickup): cho phép tối đa +100km', function () {
    // Đến điểm nhận lúc 50,100 km
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50100,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Rời kho (Đi giao hàng) nhập 50,250 km (+150 km so với 50,100 km) -> Bị từ chối
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 50250,
        'occurred_at' => now()->addMinutes(30)->toIso8601String(),
    ])->assertStatus(422)
        ->assertJsonPath('errors.km_reading.0', fn ($msg) => str_contains($msg, 'vượt quá giới hạn cho phép (+100 km)'));

    // Rời kho nhập 50,150 km (+50 km) -> Thành công
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 50150,
        'occurred_at' => now()->addMinutes(30)->toIso8601String(),
    ])->assertSuccessful();
});

test('Chặng 3 -> 4 (LeftPickup -> ArrivedDelivery): cho phép tối đa +600km', function () {
    // 2. ArrivedPickup
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50100,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // 3. LeftPickup lúc 50,120 km
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 50120,
        'occurred_at' => now()->addMinutes(20)->toIso8601String(),
    ])->assertSuccessful();

    // 4. ArrivedDelivery nhập 50,750 km (+630 km so với 50,120 km) -> Bị từ chối
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50750,
        'occurred_at' => now()->addHours(5)->toIso8601String(),
    ])->assertStatus(422)
        ->assertJsonPath('errors.km_reading.0', fn ($msg) => str_contains($msg, 'vượt quá giới hạn cho phép (+600 km)'));

    // 4. ArrivedDelivery nhập 50,600 km (+480 km) -> Thành công
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50600,
        'occurred_at' => now()->addHours(5)->toIso8601String(),
    ])->assertSuccessful();
});

test('Chặng 4 -> 5 (ArrivedDelivery -> Completed): cho phép tối đa +100km', function () {
    // 2. ArrivedPickup
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50100,
    ])->assertSuccessful();

    // 3. LeftPickup
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 50120,
    ])->assertSuccessful();

    // 4. ArrivedDelivery lúc 50,500 km
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50500,
    ])->assertSuccessful();

    // 5. Completed nhập 50,650 km (+150 km so với 50,500 km) -> Bị từ chối
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50650,
    ])->assertStatus(422)
        ->assertJsonPath('errors.km_reading.0', fn ($msg) => str_contains($msg, 'vượt quá giới hạn cho phép (+100 km)'));

    // 5. Completed nhập 50,550 km (+50 km) -> Thành công
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50550,
    ])->assertSuccessful();

    expect($this->order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('Chặng 5 -> 6 (Completed -> Complete Trip): cho phép tối đa +600km', function () {
    // 2. ArrivedPickup
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50100,
    ])->assertSuccessful();

    // 3. LeftPickup
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 50120,
    ])->assertSuccessful();

    // 4. ArrivedDelivery
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50500,
    ])->assertSuccessful();

    // 5. Completed lúc 50,520 km
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50520,
    ])->assertSuccessful();

    // 6. Complete trip với end_km = 51,200 km (+680 km so với 50,520 km) -> Bị từ chối
    $this->postJson("/api/driver/trips/{$this->trip->id}/complete", [
        'end_km' => 51200,
    ])->assertStatus(422)
        ->assertJsonPath('message.end_km.0', fn ($msg) => str_contains($msg, 'vượt quá giới hạn cho phép (+600 km)'));

    // 6. Complete trip với end_km = 50,900 km (+380 km) -> Thành công
    $this->postJson("/api/driver/trips/{$this->trip->id}/complete", [
        'end_km' => 50900,
    ])->assertSuccessful();

    expect($this->trip->fresh()->status)->toBe(TripStatus::Completed);
});

test('Chuyến nhiều điểm giao (Multi-drop): 5.1 -> 4.2 tối đa 600km, 4.2 -> 5.2 tối đa 100km', function () {
    $deliveryLoc2 = Location::create([
        'code' => 'DELIVERY-KM-2',
        'name' => 'Điểm Giao 2',
        'lat' => 10.700000,
        'lng' => 106.700000,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $dp2 = OrderDeliveryPoint::create([
        'order_id' => $this->order->id,
        'location_id' => $deliveryLoc2->id,
        'sequence' => 2,
        'address' => 'Địa chỉ giao 2',
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    // 2. ArrivedPickup
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50100,
    ])->assertSuccessful();

    // 3. LeftPickup
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 50120,
    ])->assertSuccessful();

    // 4.1. Đến điểm 1
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50300,
    ])->assertSuccessful();

    // 5.1. Giao xong điểm 1 lúc 50,330 km
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50330,
    ])->assertSuccessful();

    // 4.2. Đến điểm giao 2: chặng di chuyển (5.1 -> 4.2) cho phép đến +600km
    // Thử 50,750 km (+420 km so với 50,330 km) -> Hợp lệ
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $dp2->id,
        'km_reading' => 50750,
    ])->assertSuccessful();

    // 5.2. Giao xong điểm 2: tại điểm dỡ (4.2 -> 5.2) chỉ cho phép +100km
    // Thử 50,900 km (+150 km so với 50,750 km) -> Bị từ chối
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $dp2->id,
        'km_reading' => 50900,
    ])->assertStatus(422)
        ->assertJsonPath('errors.km_reading.0', fn ($msg) => str_contains($msg, 'vượt quá giới hạn cho phép (+100 km)'));

    // Giao xong điểm 2 với 50,800 km (+50 km) -> Thành công
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $dp2->id,
        'km_reading' => 50800,
    ])->assertSuccessful();
});

test('Bỏ qua km ở mốc trung gian: kiểm tra so với mốc có km gần nhất (max 600km)', function () {
    // 2. ArrivedPickup lúc 50,000 km
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50000,
    ])->assertSuccessful();

    // 3. LeftPickup không nhập km (null)
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::LeftPickup->value,
    ])->assertSuccessful();

    // 4. ArrivedDelivery không nhập km (null)
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
    ])->assertSuccessful();

    // 5. Completed nhập 50,650 km (+650km so với mốc 2 là 50,000 km) -> Bị từ chối
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50650,
    ])->assertStatus(422)
        ->assertJsonPath('errors.km_reading.0', fn ($msg) => str_contains($msg, 'vượt quá giới hạn cho phép (+600 km)'));

    // 5. Completed nhập 50,550 km (+550km so với 50,000 km) -> Thành công
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Completed->value,
        'order_id' => $this->order->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50550,
    ])->assertSuccessful();
});

test('Đảo lái / Bàn giao xe (Mốc 7): giới hạn tối đa +100km', function () {
    // 2. ArrivedPickup lúc 50,100 km
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 50100,
    ])->assertSuccessful();

    $service = app(TripKmLimitService::class);

    // Thử validate đảo lái với 50,250 km (+150 km so với 50,100 km) -> Không hợp lệ
    $resFail = $service->validate($this->trip, 50250, CheckpointType::DriverSwap->value);
    expect($resFail['is_valid'])->toBeFalse();
    expect($resFail['message'])->toContain('vượt quá giới hạn cho phép (+100 km)');

    // Thử validate đảo lái với 50,180 km (+80 km) -> Hợp lệ
    $resPass = $service->validate($this->trip, 50180, CheckpointType::DriverSwap->value);
    expect($resPass['is_valid'])->toBeTrue();
});
