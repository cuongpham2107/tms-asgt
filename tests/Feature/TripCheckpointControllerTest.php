<?php

use App\Enums\CheckpointType;
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

test('marks a delivery point delivered and fills delivered_at when completing a checkpoint', function () {
    $driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $driver = User::factory()->create();
    $driver->assignRole($driverRole);

    $orderCategory = OrderCategory::create([
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
        'vehicle_id' => $vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHour(),
    ]);

    $order = Order::create([
        'order_code' => 'ORD-00079',
        'type' => OrderType::Hhhk,
        'order_category_id' => $orderCategory->id,
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

    $vehicle = Vehicle::create([
        'plate_number' => '51C-543.21',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    Sanctum::actingAs($driver);

    $this->postJson('/api/driver/shifts/start', [
        'vehicle_id' => $vehicle->id,
        'shift_type' => ShiftType::Full->value,
        'start_time' => now()->toIso8601String(),
    ])->assertSuccessful()
        ->assertJsonPath('shift.driver.id', $driver->id)
        ->assertJsonPath('shift.driver.name', 'Nguyen Van A')
        ->assertJsonPath('shift.vehicle.id', $vehicle->id)
        ->assertJsonPath('shift.vehicle.plate_number', '51C-543.21');
});
