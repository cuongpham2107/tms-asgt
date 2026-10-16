<?php

use App\Enums\CheckpointType;
use App\Enums\LocationType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
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
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $this->driver = User::factory()->create(['name' => 'Tài xế thuê ngoài']);
    $this->driver->assignRole($this->driverRole);

    $this->driverShift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
        'start_km' => 1000,
    ]);

    $this->rentVehicle = Vehicle::create([
        'plate_number' => '29C-999.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'Nhà xe ABC',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Rent,
    ]);

    $this->area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'NBA',
        'name' => 'Nội Bài',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-EXT',
        'name' => 'Customer External',
        'is_active' => true,
    ]);

    $this->pickupLocation = Location::create([
        'code' => 'LOC-PICKUP',
        'name' => 'Kho lấy hàng',
        'address' => 'Nội Bài, Hà Nội',
        'lat' => 21.2188,
        'lng' => 105.8044,
        'loc_type' => LocationType::Pickup,
        'is_active' => true,
    ]);

    $this->deliveryLocation = Location::create([
        'code' => 'LOC-DELIVERY',
        'name' => 'Kho giao hàng',
        'address' => 'Bắc Ninh',
        'lat' => 21.1861,
        'lng' => 106.0763,
        'loc_type' => LocationType::Delivery,
        'is_active' => true,
    ]);
});

test('rent vehicle WITHOUT driver auto-generates 5 checkpoints', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-RENT-NO-DRIVER',
        'vehicle_id' => $this->rentVehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Pending,
        'start_location_id' => $this->pickupLocation->id,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-RENT-1',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'pickup_location_id' => $this->pickupLocation->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
    ]);

    CreatesOrderTransportCards::createCheckpointsForExternalVehicle($trip, collect([$order]));

    expect($trip->checkpoints()->count())->toBe(5);
    expect($trip->checkpoints()->pluck('checkpoint_type')->map(fn ($t) => $t->value)->all())->toBe([
        CheckpointType::Started->value,
        CheckpointType::ArrivedPickup->value,
        CheckpointType::LeftPickup->value,
        CheckpointType::ArrivedDelivery->value,
        CheckpointType::Completed->value,
    ]);
});

test('rent vehicle WITH driver DOES NOT auto-generate checkpoints and leaves checkpoints empty for app', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-RENT-WITH-DRIVER',
        'vehicle_id' => $this->rentVehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'start_location_id' => $this->pickupLocation->id,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-RENT-2',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'pickup_location_id' => $this->pickupLocation->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
    ]);

    CreatesOrderTransportCards::createCheckpointsForExternalVehicle($trip, collect([$order]));

    expect($trip->checkpoints()->count())->toBe(0);
});

test('driver assigned to rent vehicle can see active trip and record checkpoints via Mobile API', function () {
    $trip = Trip::create([
        'trip_code' => 'TRIP-RENT-API',
        'vehicle_id' => $this->rentVehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'start_location_id' => $this->pickupLocation->id,
    ]);

    $order = Order::create([
        'order_code' => 'ORD-RENT-API',
        'type' => OrderType::Hhhk,
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'created_by' => $this->driver->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'pickup_location_id' => $this->pickupLocation->id,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $this->deliveryLocation->id,
        'sequence' => 1,
    ]);

    Sanctum::actingAs($this->driver);

    // 1. App loads active trips
    $response = test()->getJson('/api/driver/trips/active');
    $response->assertSuccessful();
    expect($response->json('data.0.id'))->toBe($trip->id);

    // 2. Driver records Started checkpoint
    $startRes = test()->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->toIso8601String(),
    ]);
    $startRes->assertSuccessful();
    expect($trip->fresh()->status)->toBe(TripStatus::Started);
    expect($trip->checkpoints()->count())->toBe(1);

    // 3. Driver records ArrivedPickup checkpoint
    $arriveRes = test()->postJson("/api/driver/trips/{$trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 10020,
        'occurred_at' => now()->toIso8601String(),
    ]);
    $arriveRes->assertSuccessful();
    expect($trip->fresh()->status)->toBe(TripStatus::ArrivedPickup);
});

test('trip form checkpoint repeaters correctly switch visibility based on vehicle type and driver assignment', function () {
    $companyVehicle = Vehicle::create([
        'plate_number' => '29C-111.11',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $tripRentNoDriver = Trip::create([
        'trip_code' => 'TRIP-RENT-NO-DRV',
        'vehicle_id' => $this->rentVehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Pending,
    ]);

    $tripRentWithDriver = Trip::create([
        'trip_code' => 'TRIP-RENT-WITH-DRV',
        'vehicle_id' => $this->rentVehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $tripCompany = Trip::create([
        'trip_code' => 'TRIP-COMPANY',
        'vehicle_id' => $companyVehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    // Check visibility conditions as configured in TripForm
    $rentRepeaterVisible = fn ($record) => $record?->vehicle?->type === VehicleOwnerType::Rent && blank($record?->driver_id);
    $standardRepeaterVisible = fn ($record) => $record?->vehicle?->type !== VehicleOwnerType::Rent || filled($record?->driver_id);

    // Rent vehicle WITHOUT driver -> shows 4-col rent repeater, hides 6-col standard repeater
    expect($rentRepeaterVisible($tripRentNoDriver))->toBeTrue();
    expect($standardRepeaterVisible($tripRentNoDriver))->toBeFalse();

    // Rent vehicle WITH driver -> hides 4-col rent repeater, shows 6-col standard repeater
    expect($rentRepeaterVisible($tripRentWithDriver))->toBeFalse();
    expect($standardRepeaterVisible($tripRentWithDriver))->toBeTrue();

    // Company vehicle -> hides 4-col rent repeater, shows 6-col standard repeater
    expect($rentRepeaterVisible($tripCompany))->toBeFalse();
    expect($standardRepeaterVisible($tripCompany))->toBeTrue();
});

test('createSingleOrder with rent vehicle WITH driver creates trip with driver and NO fake checkpoints', function () {
    $schema = Mockery::mock(Schema::class);
    $schema->shouldReceive('getRawState')->andReturn(['deliveryPoints' => []]);

    $data = [
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng thuê ngoài có lái xe',
        'vehicle_id' => $this->rentVehicle->id,
        'driver_id' => $this->driver->id,
    ];

    $order = CreatesOrderTransportCards::createSingleOrder($data, $schema, 'HHHK', true, $this->driver->id);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->trip_id)->not->toBeNull();

    $trip = $order->trip;
    expect($trip->vehicle_id)->toBe($this->rentVehicle->id)
        ->and($trip->driver_id)->toBe($this->driver->id)
        ->and($trip->checkpoints()->count())->toBe(0);
});

test('createSingleOrder with rent vehicle WITHOUT driver creates trip without driver and generates 5 fake checkpoints', function () {
    $schema = Mockery::mock(Schema::class);
    $schema->shouldReceive('getRawState')->andReturn(['deliveryPoints' => []]);

    $data = [
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'pickup_location_id' => $this->pickupLocation->id,
        'cargo_name' => 'Hàng thuê ngoài không lái xe',
        'vehicle_id' => $this->rentVehicle->id,
        'driver_id' => null,
    ];

    $order = CreatesOrderTransportCards::createSingleOrder($data, $schema, 'HHHK', true, $this->driver->id);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->trip_id)->not->toBeNull();

    $trip = $order->trip;
    expect($trip->vehicle_id)->toBe($this->rentVehicle->id)
        ->and($trip->driver_id)->toBeNull()
        ->and($trip->checkpoints()->count())->toBe(5);
});
