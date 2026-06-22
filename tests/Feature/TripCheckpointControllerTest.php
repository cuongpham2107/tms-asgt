<?php

use App\Enums\CheckpointType;
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
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('marks a delivery point delivered and fills delivered_at when completing a checkpoint', function () {
    $driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $driver = User::factory()->create();
    $driver->assignRole($driverRole);

    $area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'NORTH',
        'name' => 'North',
    ]);

    $customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Customer 001',
        'is_active' => true,
    ]);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $shift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHour(),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-00079',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::ArrivedDelivery,
        'is_return_trip' => false,
        'created_by' => $driver->id,
    ]);

    $deliveryPoint = OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => OrderDeliveryPointStatus::Arrived,
        'arrived_at' => now()->subMinutes(10),
    ]);

    Sanctum::actingAs($driver);

    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'delivery_point_id' => $deliveryPoint->id,
        'checkpoint_type' => CheckpointType::Completed->value,
        'km_reading' => 10.0,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($deliveryPoint->fresh()->status)->toBe(OrderDeliveryPointStatus::Delivered);
    expect($deliveryPoint->fresh()->delivered_at)->not->toBeNull();
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);
});

test('includes driver information when starting a shift', function () {
    $driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $driver = User::factory()->create([
        'name' => 'Nguyen Van A',
    ]);
    $driver->assignRole($driverRole);

    Vehicle::create([
        'plate_number' => '51C-543.21',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $driver->id,
    ]);

    Sanctum::actingAs($driver);

    $this->postJson('/api/driver/shifts/start', [
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
    ])->assertSuccessful()
        ->assertJsonPath('shift.driver.id', $driver->id)
        ->assertJsonPath('shift.driver.name', 'Nguyen Van A');
});

test('returns 422 when checkpoint is posted for order without delivery points and missing new_delivery_location_id', function () {
    $driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $driver = User::factory()->create();
    $driver->assignRole($driverRole);

    $area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'NORTH',
        'name' => 'North',
    ]);

    $customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Customer 001',
        'is_active' => true,
    ]);

    $shift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHour(),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-NODEST',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'driver_id' => $driver->id,
        'status' => OrderStatus::Started,
        'created_by' => $driver->id,
    ]);

    Sanctum::actingAs($driver);

    // Gửi checkpoint without new_delivery_location_id
    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'occurred_at' => now()->toIso8601String(),
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Đơn hàng chưa có điểm đến. Vui lòng chọn điểm giao hàng.');

    // Gửi checkpoint WITH new_delivery_location_id -> success
    $location = Location::create([
        'code' => 'LOC-XYZ',
        'name' => 'Saigon Port',
        'address' => 'Nguyen Hue St',
        'is_active' => true,
    ]);

    $this->postJson('/api/driver/checkpoints', [
        'order_id' => $order->id,
        'shift_id' => $shift->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        'new_delivery_location_id' => $location->id,
        'occurred_at' => now()->toIso8601String(),
    ])->assertSuccessful();

    expect($order->deliveryPoints()->count())->toBe(1);
    expect($order->deliveryPoints()->first()->location_id)->toBe($location->id);
});
