<?php

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'TEST',
        'name' => 'Test Area',
    ]);
    $this->customer = Customer::create([
        'code' => 'CUST-TEST',
        'name' => 'Test Customer',
        'is_active' => true,
    ]);
    $this->vehicle = Vehicle::create([
        'plate_number' => 'TEST-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 50000,
    ]);
    $this->driver = User::factory()->create(['name' => 'Driver']);
    $this->driver->assignRole($this->driverRole);
    $this->vehicle->update(['current_driver_id' => $this->driver->id]);

    $this->pickupLocation = Location::create([
        'code' => 'PICKUP',
        'name' => 'Pickup Location',
        'lat' => 10.818889,
        'lng' => 106.651944,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);
    $this->deliveryLocation = Location::create([
        'code' => 'DELIVERY',
        'name' => 'Delivery Location',
        'lat' => 10.764722,
        'lng' => 106.781944,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-TEST-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $this->order1 = Order::create([
        'order_code' => 'ORD-TEST-001',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Pickup address',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $this->order2 = Order::create([
        'order_code' => 'ORD-TEST-002',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Pickup address 2',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $this->dp1 = OrderDeliveryPoint::create([
        'order_id' => $this->order1->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'Delivery address',
        'status' => 'pending',
    ]);

    $this->dp2 = OrderDeliveryPoint::create([
        'order_id' => $this->order2->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'Delivery address 2',
        'status' => 'pending',
    ]);

    Sanctum::actingAs($this->driver);
});

test('started creates checkpoints for all orders in trip', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
        'gps_lat' => 10.818889,
        'gps_lng' => 106.651944,
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Started);

    $checkpoints = $this->trip->checkpoints;
    expect($checkpoints)->toHaveCount(2);
    expect($checkpoints->pluck('order_id')->sort()->values()->toArray())->toBe([$this->order1->id, $this->order2->id]);
});

test('started updates trip.shift_id from driver active shift', function () {
    $shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect((int) $this->trip->shift_id)->toBe($shift->id);
});

test('started with km_reading succeeds', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'km_reading' => 50010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();
});

test('arrived_pickup requires km_reading', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_pickup',
        'km_reading' => 50010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::ArrivedPickup);
});

test('arrived_pickup without km_reading fails', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422);
});

test('arrived_delivery requires order_id and delivery_point_id', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::ArrivedDelivery);
    expect($this->dp1->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);
});

test('arrived_delivery without order_id fails', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422);
});

test('completed without order_id fails validation', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'km_reading' => 50050,
    ])->assertStatus(422);
});

test('completed completes all orders at same location and auto-completes trip', function () {
    // Cả 2 orders cùng location_id → arrived_delivery cho 1 order sẽ tạo cho cả 2
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $checkpoints = $this->trip->checkpoints()->where('checkpoint_type', 'arrived_delivery')->get();
    expect($checkpoints)->toHaveCount(2);
    expect($checkpoints->pluck('order_id')->sort()->values()->toArray())
        ->toBe([$this->order1->id, $this->order2->id]);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Completed cho 1 order cùng location → complete cả 2 → trip completes
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50050,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($this->order1->fresh()->status)->toBe(OrderStatus::Completed);
    expect($this->order2->fresh()->status)->toBe(OrderStatus::Completed);

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Completed);
});

test('completed handles orders at different locations separately', function () {
    // Tạo order3 với delivery location khác
    $otherLocation = Location::create([
        'code' => 'OTHER-DELIVERY',
        'name' => 'Other Delivery Location',
        'lat' => 10.700000,
        'lng' => 106.700000,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $order3 = Order::create([
        'order_code' => 'ORD-TEST-003',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Pickup address 3',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $dp3 = OrderDeliveryPoint::create([
        'order_id' => $order3->id,
        'location_id' => $otherLocation->id,
        'sequence' => 1,
        'address' => 'Different delivery address',
        'status' => 'pending',
    ]);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Arrived delivery cho order1 (cùng location với order2)
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50050,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // order1 và order2 cùng location → cả 2 completed
    expect($this->order1->fresh()->status)->toBe(OrderStatus::Completed);
    expect($this->order2->fresh()->status)->toBe(OrderStatus::Completed);

    // order3 khác location → chưa completed
    expect($order3->fresh()->status)->toBe(OrderStatus::InTransit);

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::ArrivedDelivery);

    // Arrived delivery cho order3
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order3->id,
        'delivery_point_id' => $dp3->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $order3->id,
        'delivery_point_id' => $dp3->id,
        'km_reading' => 50090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($order3->fresh()->status)->toBe(OrderStatus::Completed);

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Completed);
});

test('arrived_delivery groups orders at same location', function () {
    // Cả 2 orders cùng location_id → arrived_delivery tạo checkpoint cho cả 2
    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Response trả về mảng checkpoints
    $response->assertJsonStructure(['checkpoints']);
    expect(collect($response->json('checkpoints')))->toHaveCount(2);

    // Verify delivery points status
    expect($this->dp1->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);
    expect($this->dp2->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);
});

test('arrived_delivery without delivery_point_id does not group', function () {
    // Tạo order3 chưa có delivery point nào
    $order3 = Order::create([
        'order_code' => 'ORD-TEST-NO-DP',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Pickup address no dp',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Không có delivery_point_id, nhưng có new_delivery_location_id
    $newLocation = Location::create([
        'code' => 'NEW-DELIVERY',
        'name' => 'New Delivery Location',
        'lat' => 10.700000,
        'lng' => 106.700000,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order3->id,
        'new_delivery_location_id' => $newLocation->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Chỉ tạo 1 checkpoint cho order3, không gộp với các order khác
    expect(collect($response->json('checkpoints')))->toHaveCount(1);
});

test('arrived_delivery skips duplicate checkpoints in group', function () {
    // arrived_delivery cho order1 → tạo cho cả 2
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Gửi lại arrived_delivery cho order2 → order2 đã có rồi, skip
    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order2->id,
        'delivery_point_id' => $this->dp2->id,
        'occurred_at' => now()->toIso8601String(),
    ]);

    // Nếu cả 2 đã có, response vẫn thành công với checkpoints rỗng
    $response->assertSuccessful();
    expect(collect($response->json('checkpoints')))->toHaveCount(0);
});

test('completed does not complete order with remaining delivery points', function () {
    // Tạo order có 2 delivery points ở 2 locations khác nhau
    $order3 = Order::create([
        'order_code' => 'ORD-MULTI-DP',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Pickup address',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $otherLocation = Location::create([
        'code' => 'OTHER-LOC',
        'name' => 'Other Location',
        'lat' => 10.700000,
        'lng' => 106.700000,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $dpSeq1 = OrderDeliveryPoint::create([
        'order_id' => $order3->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'address' => 'First stop',
        'status' => 'pending',
    ]);

    $dpSeq2 = OrderDeliveryPoint::create([
        'order_id' => $order3->id,
        'location_id' => $otherLocation->id,
        'sequence' => 2,
        'address' => 'Second stop',
        'status' => 'pending',
    ]);

    // left_pickup
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // arrived_delivery + completed cho dp seq 1
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order3->id,
        'delivery_point_id' => $dpSeq1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $order3->id,
        'delivery_point_id' => $dpSeq1->id,
        'km_reading' => 50050,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order chưa completed vì còn delivery point seq 2
    expect($order3->fresh()->status)->toBe(OrderStatus::InTransit);
    expect($dpSeq1->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);
    expect($dpSeq2->fresh()->status)->toBe(OrderDeliveryPointStatus::Pending);

    // arrived_delivery + completed cho dp seq 2
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order3->id,
        'delivery_point_id' => $dpSeq2->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $order3->id,
        'delivery_point_id' => $dpSeq2->id,
        'km_reading' => 50090,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Order completed sau khi cả 2 delivery points done
    expect($order3->fresh()->status)->toBe(OrderStatus::Completed);
    expect($dpSeq2->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);

    // Trip auto-complete
    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Completed);
});

test('arrived_delivery with photos attaches to all grouped checkpoints', function () {
    // Tạo file ảnh giả
    $file = UploadedFile::fake()->image('test.jpg');

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
        'photos' => [$file],
    ])->assertSuccessful();

    // Verify 2 checkpoints đều có photo
    $checkpoints = $this->trip->checkpoints()->where('checkpoint_type', 'arrived_delivery')->get();
    foreach ($checkpoints as $cp) {
        expect($cp->photos)->toHaveCount(1);
    }
});

test('unauthorized driver gets 403', function () {
    $otherDriver = User::factory()->create();
    $otherDriver->assignRole($this->driverRole);
    Sanctum::actingAs($otherDriver);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
    ])->assertStatus(403);
});

test('order not in trip returns 422', function () {
    $otherTrip = Trip::create([
        'trip_code' => 'TRIP-OTHER',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->postJson("/api/driver/trips/{$otherTrip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422);
});

test('left_pickup updates trip status to delivering', function () {
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Delivering);
});
