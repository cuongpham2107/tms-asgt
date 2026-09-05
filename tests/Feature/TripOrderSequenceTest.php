<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Order;
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
});

test('cargo trip can be started even if an empty run exists for driver and vehicle', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-111.11',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 10000,
    ]);

    $shift = DriverShift::create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'shift_type' => 'full',
        'start_time' => now()->subHour(),
        'start_km' => 10000,
    ]);

    // Tạo chuyến không hàng trước (ReturnTrip)
    $emptyTrip = Trip::create([
        'trip_code' => 'TRIP-EMPTY-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::ReturnTrip,
        'is_empty_run' => true,
        'start_km' => 10000,
    ]);

    // Tạo chuyến có hàng (Pending)
    $cargoTrip = Trip::create([
        'trip_code' => 'TRIP-CARGO-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Pending,
        'is_empty_run' => false,
    ]);

    $area = Area::create(['code' => 'AREA-01', 'name' => 'Khu vực 1']);
    $customer = Customer::create(['name' => 'Khách Hàng 1', 'code' => 'CUST-01', 'is_active' => true]);

    $order = Order::create([
        'order_code' => 'ORD-CARGO-01',
        'status' => OrderStatus::Sent,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $cargoTrip->id,
        'created_by' => $driver->id,
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($driver);

    // Lái xe bắt đầu chuyến có hàng
    $response = $this->postJson("/api/driver/trips/{$cargoTrip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'km_reading' => 10000,
        'occurred_at' => now()->toISOString(),
    ]);

    $response->assertSuccessful();
    $cargoTrip->refresh();
    expect($cargoTrip->status)->toBe(TripStatus::Started);
});

test('empty run cannot be completed while a cargo trip is in progress', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-222.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 20000,
    ]);

    $shift = DriverShift::create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'shift_type' => 'full',
        'start_time' => now()->subHour(),
        'start_km' => 20000,
    ]);

    // Chuyến có hàng đang chạy (Delivering)
    $cargoTrip = Trip::create([
        'trip_code' => 'TRIP-CARGO-02',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Delivering,
        'is_empty_run' => false,
        'start_km' => 20000,
    ]);

    $area = Area::create(['code' => 'AREA-02', 'name' => 'Khu vực 2']);
    $customer = Customer::create(['name' => 'Khách Hàng 2', 'code' => 'CUST-02', 'is_active' => true]);

    Order::create([
        'order_code' => 'ORD-CARGO-02',
        'status' => OrderStatus::InTransit,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $cargoTrip->id,
        'created_by' => $driver->id,
        'sent_at' => now(),
    ]);

    // Chuyến không hàng
    $emptyTrip = Trip::create([
        'trip_code' => 'TRIP-EMPTY-02',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::ReturnTrip,
        'is_empty_run' => true,
        'start_km' => 20050,
    ]);

    Sanctum::actingAs($driver);

    // Lái xe cố tình complete chuyến không hàng khi chuyến có hàng chưa xong
    $response = $this->postJson("/api/driver/trips/{$emptyTrip->id}/complete", [
        'end_km' => 20080,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Xe 29C-222.22 đang có chuyến hàng thực hiện. Vui lòng hoàn thành hoặc đảo lái trước khi kết thúc chuyến không hàng.',
        ]);

    $emptyTrip->refresh();
    expect($emptyTrip->status)->toBe(TripStatus::ReturnTrip);
});

test('empty run cannot record checkpoints while a cargo trip is in progress', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-333.33',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 30000,
    ]);

    $shift = DriverShift::create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'shift_type' => 'full',
        'start_time' => now()->subHour(),
        'start_km' => 30000,
    ]);

    // Chuyến có hàng đang chạy
    $cargoTrip = Trip::create([
        'trip_code' => 'TRIP-CARGO-03',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Started,
        'is_empty_run' => false,
        'start_km' => 30000,
    ]);

    $area = Area::create(['code' => 'AREA-03', 'name' => 'Khu vực 3']);
    $customer = Customer::create(['name' => 'Khách Hàng 3', 'code' => 'CUST-03', 'is_active' => true]);

    Order::create([
        'order_code' => 'ORD-CARGO-03',
        'status' => OrderStatus::InTransit,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $cargoTrip->id,
        'created_by' => $driver->id,
        'sent_at' => now(),
    ]);

    // Chuyến không hàng
    $emptyTrip = Trip::create([
        'trip_code' => 'TRIP-EMPTY-03',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::ReturnTrip,
        'is_empty_run' => true,
        'start_km' => 30050,
    ]);

    Sanctum::actingAs($driver);

    // Gửi checkpoint Started trên chuyến không hàng
    $response = $this->postJson("/api/driver/trips/{$emptyTrip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'km_reading' => 30050,
        'occurred_at' => now()->toISOString(),
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['checkpoint_type']);
});

test('empty run can be completed after cargo trip is completed or driver swapped', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-444.44',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 40050,
    ]);

    $shift = DriverShift::create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'shift_type' => 'full',
        'start_time' => now()->subHours(2),
        'start_km' => 40000,
    ]);

    // Chuyến có hàng ĐÃ HOÀN THÀNH
    $cargoTrip = Trip::create([
        'trip_code' => 'TRIP-CARGO-04',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Completed,
        'is_empty_run' => false,
        'start_km' => 40000,
        'end_km' => 40050,
    ]);

    // Chuyến không hàng
    $emptyTrip = Trip::create([
        'trip_code' => 'TRIP-EMPTY-04',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::ReturnTrip,
        'is_empty_run' => true,
        'start_km' => 40050,
    ]);

    Sanctum::actingAs($driver);

    // Lái xe hoàn thành chuyến không hàng thành công
    $response = $this->postJson("/api/driver/trips/{$emptyTrip->id}/complete", [
        'end_km' => 40080,
    ]);

    $response->assertSuccessful();
    $emptyTrip->refresh();
    expect($emptyTrip->status)->toBe(TripStatus::Completed);
});

test('api current and active endpoints prioritize cargo trip over empty run', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '29C-555.55',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 50000,
    ]);

    // Tạo chuyến không hàng trước
    $emptyTrip = Trip::create([
        'trip_code' => 'TRIP-EMPTY-05',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::ReturnTrip,
        'is_empty_run' => true,
        'created_at' => now()->addMinute(),
    ]);

    // Tạo chuyến có hàng
    $cargoTrip = Trip::create([
        'trip_code' => 'TRIP-CARGO-05',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
        'is_empty_run' => false,
        'created_at' => now(),
    ]);

    $area = Area::create(['code' => 'AREA-05', 'name' => 'Khu vực 5']);
    $customer = Customer::create(['name' => 'Khách Hàng 5', 'code' => 'CUST-05', 'is_active' => true]);

    Order::create([
        'order_code' => 'ORD-CARGO-05',
        'status' => OrderStatus::Sent,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $cargoTrip->id,
        'created_by' => $driver->id,
    ]);

    Sanctum::actingAs($driver);

    // API current phải trả về chuyến có hàng
    $currentRes = $this->getJson('/api/driver/trips/current');
    $currentRes->assertSuccessful();
    expect($currentRes->json('data.trip.id'))->toBe($cargoTrip->id);

    // API active phải trả về danh sách với chuyến có hàng đứng đầu
    $activeRes = $this->getJson('/api/driver/trips/active');
    $activeRes->assertSuccessful();
    expect($activeRes->json('data.0.id'))->toBe($cargoTrip->id);
});
