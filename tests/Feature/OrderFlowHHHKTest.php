<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\LocationType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Priority;
use App\Enums\ShiftType;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
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

    // Điểm lấy hàng A
    $this->locationA = Location::create([
        'code' => 'A-PICKUP',
        'name' => 'Kho A - Tân Sơn Nhất',
        'address' => 'Sân bay Tân Sơn Nhất, Quận Tân Bình, TP.HCM',
        'lat' => 10.818889,
        'lng' => 106.651944,
        'loc_type' => LocationType::Pickup,
        'is_active' => true,
    ]);

    // Điểm giao hàng B
    $this->locationB = Location::create([
        'code' => 'B-DELIVERY',
        'name' => 'Kho B - Cát Lái',
        'address' => 'Cảng Cát Lái, Quận 2, TP.HCM',
        'lat' => 10.764722,
        'lng' => 106.781944,
        'loc_type' => LocationType::Delivery,
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

    $this->driver = User::factory()->create([
        'name' => 'Tài xế Nguyễn Văn A',
    ]);
    $this->driver->assignRole($this->driverRole);

    // Gắn tài xế cho xe (mặc định xe đã gắn tài xế)
    $this->vehicle->update(['current_driver_id' => $this->driver->id]);
});

test('luồng đơn hàng HHHK từ A->B: tạo order, ca trực, điều hàng, chốt chặng và hoàn thành', function () {
    // ============================================
    // 1. TẠO ĐƠN HÀNG HHHK TỪ A -> B
    // ============================================
    $order = Order::create([
        'order_code' => 'ORD-HHHK-001',
        'type' => OrderType::Hhhk,
        'order_category_id' => $this->orderCategory->id,
        'customer_id' => $this->customer->id,
        'cargo_name' => 'Hàng điện tử',
        'cargo_type' => CargoType::Gcr,
        'total_packages' => 10,
        'total_weight' => 500.00,
        'pickup_location_id' => $this->locationA->id,
        'pickup_address' => $this->locationA->address,
        'pickup_contact' => 'Anh Quang',
        'pickup_phone' => '0909123456',
        'planned_loading_at' => now()->addHour(),
        'priority' => Priority::High,
        'status' => OrderStatus::Draft,
        'is_return_trip' => false,
        'created_by' => $this->driver->id,
        'notes' => 'Giao hàng gấp trong ngày',
    ]);

    expect($order->status)->toBe(OrderStatus::Draft);
    expect($order->pickupLocation->code)->toBe('A-PICKUP');

    // Tạo điểm giao hàng B
    $deliveryPoint = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->locationB->id,
        'sequence' => 1,
        'address' => $this->locationB->address,
        'contact_person' => 'Anh Bình',
        'contact_phone' => '0909987654',
        'total_packages' => 10,
        'total_weight' => 500.00,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    expect($deliveryPoint->status)->toBe(OrderDeliveryPointStatus::Pending);

    // ============================================
    // 2. ĐIỀU HÀNG: GẮN TÀI XẾ + XE CHO ĐƠN HÀNG
    //    Draft -> Assigned -> Sent
    // ============================================
    // 2a. Gán tài xế và xe (Draft -> Assigned)
    $order->update([
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => OrderStatus::Assigned,
    ]);

    expect($order->fresh()->status)->toBe(OrderStatus::Assigned);
    expect($order->fresh()->vehicle_id)->toBe($this->vehicle->id);
    expect($order->fresh()->driver_id)->toBe($this->driver->id);

    // 2b. Gửi lệnh cho tài xế (Assigned -> Sent)
    $order->update([
        'status' => OrderStatus::Sent,
        'sent_at' => now(),
    ]);

    expect($order->fresh()->status)->toBe(OrderStatus::Sent);

    // ============================================
    // 3. TẠO CA TRỰC CHO TÀI XẾ
    // ============================================
    Sanctum::actingAs($this->driver);

    $shiftResponse = $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shift = DriverShift::find($shiftResponse->json('shift.id'));

    expect($shift->shift_type)->toBe(ShiftType::Full);
    expect($shift->start_time)->not->toBeNull();

    // ============================================
    // 4. TẠO TRIP CHECKPOINTS CHO TUYẾN A -> B
    // ============================================
    // 4a. Bắt đầu chuyến (km tự lấy từ vehicle.current_mileage)
    $this->vehicle->update(['current_mileage' => 15000]);
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'gps_lat' => 10.818889,
        'gps_lng' => 106.651944,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Started);

    // 4b. Đến lấy hàng (tại điểm A)
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 15005,
        'gps_lat' => 10.818889,
        'gps_lng' => 106.651944,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::ArrivedPickup);
    expect($deliveryPoint->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);

    // 4c. Rời điểm lấy hàng (bắt đầu giao)
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'checkpoint_type' => CheckpointType::LeftPickup->value,
        'km_reading' => 15010,
        'gps_lat' => 10.820000,
        'gps_lng' => 106.660000,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Delivering);

    // 4d. Đến điểm giao hàng (tại điểm B)
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'km_reading' => 15055,
        'gps_lat' => 10.764722,
        'gps_lng' => 106.781944,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::ArrivedDelivery);

    // 4e. Hoàn thành giao hàng
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 15060,
        'gps_lat' => 10.764722,
        'gps_lng' => 106.781944,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
    expect($deliveryPoint->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);

    // ============================================
    // 5. KẾT THÚC CA LÀM
    // ============================================
    $this->postJson('/api/driver/shifts/end', [
        'end_km' => 15100,
        'end_time' => now()->toIso8601String(),
    ])->assertSuccessful();

    $shift->refresh();

    // total_km = end_km - start_km = 15100 - 15000 = 100
    expect((float) $shift->total_km)->toBe(100.0);

    // loaded = completed.km (15060) - arrived_pickup.km (15005) = 55
    expect((float) $shift->total_km_loaded)->toBe(55.0);

    // empty = total - loaded = 100 - 55 = 45
    expect((float) $shift->total_km_empty)->toBe(45.0);

    // Kiểm tra driver không còn gắn với xe sau khi hết ca
    expect($this->vehicle->fresh()->current_driver_id)->toBeNull();
});
