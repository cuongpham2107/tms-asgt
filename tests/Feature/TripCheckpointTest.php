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
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
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
    $this->driverShift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
        'start_km' => 50000,
    ]);

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
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    $this->trip->refresh();
    expect((int) $this->trip->shift_id)->toBe($this->driverShift->id);
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

test('completed completes all orders and trip via manual complete endpoint', function () {
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

    // Completed cho 1 order cùng location → complete cả 2 orders
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'km_reading' => 50050,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($this->order1->fresh()->status)->toBe(OrderStatus::Completed);
    expect($this->order2->fresh()->status)->toBe(OrderStatus::Completed);

    // Trip is NOT auto-completed — must call complete endpoint
    $this->trip->refresh();
    expect($this->trip->status)->not->toBe(TripStatus::Completed);

    // Manual complete
    $this->postJson("/api/driver/trips/{$this->trip->id}/complete", [
        'end_km' => 50090,
    ])->assertSuccessful();

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
    // Trip is NOT auto-completed
    expect($this->trip->status)->not->toBe(TripStatus::Completed);

    // Manual complete
    $this->postJson("/api/driver/trips/{$this->trip->id}/complete", [
        'end_km' => 50100,
    ])->assertSuccessful();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Completed);
});

test('arrived_delivery groups orders at same location', function () {
    // Start trip first so auto-start doesn't add checkpoints to the arrived_delivery response
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Cả 2 orders cùng location_id → arrived_delivery tạo checkpoint cho cả 2
    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $this->order1->id,
        'delivery_point_id' => $this->dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Response trả về mảng checkpoints (chỉ chứa arrived_delivery, không chứa auto-start)
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

    // Trip is NOT auto-completed — must call complete endpoint
    $this->trip->refresh();
    expect($this->trip->status)->not->toBe(TripStatus::Completed);

    $this->postJson("/api/driver/trips/{$this->trip->id}/complete", [
        'end_km' => 50100,
    ])->assertSuccessful();

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

test('cannot start new trip when driver has active trip', function () {
    // Bắt đầu chuyến hiện tại
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Tạo chuyến mới pending
    $otherTrip = Trip::create([
        'trip_code' => 'TRIP-TEST-002',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    // Cố bắt đầu chuyến mới → bị từ chối
    $this->postJson("/api/driver/trips/{$otherTrip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422)
        ->assertJsonPath('errors.checkpoint_type.0', function ($msg) {
            return str_contains($msg, 'chưa hoàn thiện chuyến')
                && str_contains($msg, 'bắt đầu chuyến mới');
        });

    // Chuyến mới vẫn pending
    expect($otherTrip->fresh()->status)->toBe(TripStatus::Pending);
});

test('cannot auto-start trip via arrived_pickup when driver has active trip', function () {
    // Bắt đầu chuyến hiện tại
    $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'km_reading' => 50010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // Tạo chuyến mới pending
    $otherTrip = Trip::create([
        'trip_code' => 'TRIP-TEST-003',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $otherOrder = Order::create([
        'order_code' => 'ORD-TEST-003',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $otherTrip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'Pickup address',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    // Gửi arrived_pickup cho chuyến mới → auto-start bị từ chối vì xe đang có chuyến active
    $this->postJson("/api/driver/trips/{$otherTrip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_pickup',
        'km_reading' => 50050,
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422)
        ->assertJsonPath('errors.checkpoint_type.0', function ($msg) {
            return str_contains($msg, 'Xe biển số TEST-001')
                && str_contains($msg, 'đang chạy chuyến')
                && str_contains($msg, $this->driver->name);
        });

    // Chuyến mới vẫn pending
    expect($otherTrip->fresh()->status)->toBe(TripStatus::Pending);
});

test('arrived_delivery and completed handle order with repeated locations in route (A -> B -> A)', function () {
    $locA = Location::create([
        'code' => 'ACSV',
        'name' => 'ACSV',
        'lat' => 21.2188925,
        'lng' => 105.8044596,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $locB = Location::create([
        'code' => 'ALSC',
        'name' => 'ALSC',
        'lat' => 21.2188925,
        'lng' => 105.8044596,
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ROUTE-ABA',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-ABA',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'pickup_address' => 'ALSB',
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
    ]);

    $dp1 = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $locA->id,
        'sequence' => 1,
        'address' => 'ACSV Stop 1',
        'status' => 'pending',
    ]);

    $dp2 = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $locB->id,
        'sequence' => 2,
        'address' => 'ALSC Stop 2',
        'status' => 'pending',
    ]);

    $dp3 = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $locA->id,
        'sequence' => 3,
        'address' => 'ACSV Stop 3',
        'status' => 'pending',
    ]);

    // 1. Started
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'started',
        'km_reading' => 50010,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // 2. Arrived pickup
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_pickup',
        'km_reading' => 50020,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // 3. Left pickup
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    // 4. Arrived at Stop 1 (ACSV) -> Must update dp1, NOT dp3!
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order->id,
        'delivery_point_id' => $dp1->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($dp1->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);
    expect($dp2->fresh()->status)->toBe(OrderDeliveryPointStatus::Pending);
    expect($dp3->fresh()->status)->toBe(OrderDeliveryPointStatus::Pending);

    // 5. Complete Stop 1 (ACSV)
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $order->id,
        'delivery_point_id' => $dp1->id,
        'km_reading' => 50040,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($dp1->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);
    expect($dp2->fresh()->status)->toBe(OrderDeliveryPointStatus::Pending);
    expect($dp3->fresh()->status)->toBe(OrderDeliveryPointStatus::Pending);
    expect($order->fresh()->status)->toBe(OrderStatus::InTransit);

    // 6. Arrived at Stop 2 (ALSC)
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order->id,
        'delivery_point_id' => $dp2->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($dp2->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);

    // 7. Complete Stop 2 (ALSC)
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $order->id,
        'delivery_point_id' => $dp2->id,
        'km_reading' => 50050,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($dp2->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);
    expect($dp3->fresh()->status)->toBe(OrderDeliveryPointStatus::Pending);
    expect($order->fresh()->status)->toBe(OrderStatus::InTransit);

    // 8. Arrived at Stop 3 (ACSV) -> Must update dp3!
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order->id,
        'delivery_point_id' => $dp3->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($dp3->fresh()->status)->toBe(OrderDeliveryPointStatus::Arrived);

    // 9. Complete Stop 3 (ACSV) -> Order completed!
    $this->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => 'completed',
        'order_id' => $order->id,
        'delivery_point_id' => $dp3->id,
        'km_reading' => 50060,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($dp3->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('adding a new checkpoint in trip form pre-fills values from previous checkpoint', function () {
    $trip = Trip::create([
        'trip_code' => 'TEST-TRIP-PREFILL',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
        'start_km' => 50000,
        'started_at' => now(),
    ]);

    $order = Order::create([
        'order_code' => 'TEST-ORD-PREFILL',
        'type' => OrderType::Hhhk,
        'trip_id' => $trip->id,
        'customer_id' => $this->customer->id,
        'area_id' => $this->area->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->driver->id,
    ]);

    $dp = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Pending,
    ]);

    $trip->checkpoints()->create([
        'checkpoint_type' => 'started',
        'order_id' => $order->id,
        'driver_id' => $this->driver->id,
        'km_reading' => 50010.5,
        'occurred_at' => '2026-10-19 09:00',
        'delivery_point_id' => $dp->id,
    ]);

    $form = Schema::make(Livewire::new(ListTrips::class));
    TripForm::configure($form);
    $form->record($trip);
    $form->fill([
        'checkpoints' => [
            'row-1' => [
                'checkpoint_type' => 'started',
                'order_id' => $order->id,
                'driver_id' => $this->driver->id,
                'km_reading' => 50010.5,
                'occurred_at' => '2026-10-19 09:00',
                'delivery_point_id' => $dp->id,
            ],
        ],
    ]);

    $comp = $form->getFlatComponents()['checkpoints'];
    $addAction = $comp->getAddAction();
    $addAction->call(['component' => $comp]);

    $schemas = $comp->getChildSchemas();
    expect($schemas)->toHaveCount(2);

    $newRow = end($schemas);
    $flat = $newRow->getFlatComponents();

    expect($flat['checkpoint_type']->getState())->toEqual(CheckpointType::ArrivedPickup)
        ->and($flat['order_id']->getState())->toBe($order->id)
        ->and($flat['driver_id']->getState())->toBe($this->driver->id)
        ->and((float) $flat['km_reading']->getState())->toBe(50010.5)
        ->and($flat['occurred_at']->getState())->toBe('2026-10-19 09:00')
        ->and($flat['delivery_point_id']->getState())->toBe($dp->id);
});

test('adding a new checkpoint advances checkpoint_type correctly and skips driver_swap', function () {
    $trip = Trip::create([
        'trip_code' => 'TEST-TRIP-ADVANCE',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
        'start_km' => 50000,
        'started_at' => now(),
    ]);

    $order = Order::create([
        'order_code' => 'TEST-ORD-ADVANCE',
        'type' => OrderType::Hhhk,
        'trip_id' => $trip->id,
        'customer_id' => $this->customer->id,
        'area_id' => $this->area->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->driver->id,
    ]);

    $trip->checkpoints()->create([
        'checkpoint_type' => 'left_pickup',
        'order_id' => $order->id,
        'driver_id' => $this->driver->id,
        'occurred_at' => now()->subMinutes(10),
    ]);
    $trip->checkpoints()->create([
        'checkpoint_type' => 'driver_swap',
        'order_id' => $order->id,
        'driver_id' => $this->driver->id,
        'occurred_at' => now()->subMinutes(5),
    ]);

    $form = Schema::make(Livewire::new(ListTrips::class));
    TripForm::configure($form);
    $form->record($trip);
    $form->loadStateFromRelationships();

    // Case 1: LeftPickup -> DriverSwap -> next row defaults to ArrivedDelivery
    $comp = $form->getFlatComponents()['checkpoints'];
    $comp->getAddAction()->call(['component' => $comp]);
    $schemas = $comp->getChildSchemas();
    $newRow = end($schemas);
    expect($newRow->getFlatComponents()['checkpoint_type']->getState())->toEqual(CheckpointType::ArrivedDelivery);

    // Case 2: ArrivedDelivery -> next row defaults to Completed
    $trip2 = Trip::create([
        'trip_code' => 'TEST-TRIP-ADVANCE-2',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
        'start_km' => 50000,
        'started_at' => now(),
    ]);
    $trip2->checkpoints()->create([
        'checkpoint_type' => 'arrived_delivery',
        'order_id' => $order->id,
        'driver_id' => $this->driver->id,
        'occurred_at' => now(),
    ]);

    $form2 = Schema::make(Livewire::new(ListTrips::class));
    TripForm::configure($form2);
    $form2->record($trip2);
    $form2->loadStateFromRelationships();

    $comp2 = $form2->getFlatComponents()['checkpoints'];
    $comp2->getAddAction()->call(['component' => $comp2]);
    $schemas2 = $comp2->getChildSchemas();
    $newRow2 = end($schemas2);
    expect($newRow2->getFlatComponents()['checkpoint_type']->getState())->toEqual(CheckpointType::Completed);
});

test('adding a new checkpoint in rental trip form advances checkpoint_type correctly', function () {
    $rentVehicle = Vehicle::create([
        'plate_number' => '29C-RENT-001',
        'type' => VehicleOwnerType::Rent,
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'RENTAL',
        'is_active' => true,
        'status' => VehicleStatus::On,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TEST-TRIP-RENT-ADVANCE',
        'vehicle_id' => $rentVehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Started,
        'started_at' => now(),
    ]);

    $trip->checkpoints()->create([
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->subMinutes(10),
    ]);
    $trip->checkpoints()->create([
        'checkpoint_type' => 'driver_swap',
        'occurred_at' => now()->subMinutes(5),
    ]);

    $form = Schema::make(Livewire::new(ListTrips::class));
    TripForm::configure($form);
    $form->record($trip);
    $form->loadStateFromRelationships();

    // From left_pickup -> driver_swap -> next should be arrived_delivery
    $comp = $form->getFlatComponents()['checkpoints_rent'];
    $comp->getAddAction()->call(['component' => $comp]);
    $schemas = $comp->getChildSchemas();
    $newRow = end($schemas);
    expect($newRow->getFlatComponents()['checkpoint_type']->getState())->toEqual(CheckpointType::ArrivedDelivery);
});
