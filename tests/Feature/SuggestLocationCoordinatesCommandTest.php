<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driver = User::factory()->create();
    $vehicle = Vehicle::create([
        'plate_number' => '29C-00001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 1000,
    ]);
    $this->trip = Trip::create([
        'trip_code' => 'CD-TEST-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
    ]);

    // Toạ độ đang lưu lệch ~1km về phía nam so với nơi tài xế thực sự đứng.
    $this->location = Location::create([
        'code' => 'KHO-A',
        'name' => 'Kho A',
        'lat' => 21.0000000,
        'lng' => 105.0000000,
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'TEST', 'name' => 'Test Area']);
    $customer = Customer::create(['code' => 'CUST-TEST', 'name' => 'Test Customer', 'is_active' => true]);
    $this->order = Order::create([
        'order_code' => 'ORD-TEST-001',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->location->id,
        'status' => OrderStatus::Completed,
        'created_by' => $this->driver->id,
    ]);
    $this->deliveryPoint = OrderDeliveryPoint::create([
        'order_id' => $this->order->id,
        'location_id' => $this->location->id,
        'sequence' => 1,
        'address' => 'Kho A',
        'status' => 'pending',
    ]);
});

function recordGps(Trip $trip, Order $order, CheckpointType $type, float $lat, float $lng, ?int $deliveryPointId = null): void
{
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $deliveryPointId,
        'driver_id' => $trip->driver_id,
        'checkpoint_type' => $type,
        'occurred_at' => now(),
        'gps_lat' => $lat,
        'gps_lng' => $lng,
    ]);
}

function seedDriverCluster(object $test): void
{
    foreach ([21.0090, 21.0091, 21.0089] as $lat) {
        recordGps($test->trip, $test->order, CheckpointType::ArrivedPickup, $lat, 105.0001);
    }
    foreach ([21.0090, 21.0092] as $lat) {
        recordGps($test->trip, $test->order, CheckpointType::Completed, $lat, 105.0001, $test->deliveryPoint->id);
    }

    // Mốc gửi từ xa (về nhà mới gửi) — phải bị loại khỏi phép tính.
    recordGps($test->trip, $test->order, CheckpointType::LeftPickup, 21.2000, 105.3000);
}

test('suggests the median of driver positions and ignores far outliers without changing data', function () {
    seedDriverCluster($this);

    $this->artisan('locations:suggest-coordinates')
        ->expectsOutputToContain('KHO-A')
        ->assertSuccessful();

    expect((float) $this->location->fresh()->lat)->toBe(21.0);
});

test('apply writes the suggested coordinates', function () {
    seedDriverCluster($this);

    $this->artisan('locations:suggest-coordinates', ['--apply' => true])->assertSuccessful();

    $location = $this->location->fresh();
    expect((float) $location->lat)->toBe(21.009)
        ->and((float) $location->lng)->toBe(105.0001);
});

test('skips locations with too few samples', function () {
    recordGps($this->trip, $this->order, CheckpointType::ArrivedPickup, 21.0090, 105.0001);
    recordGps($this->trip, $this->order, CheckpointType::ArrivedPickup, 21.0091, 105.0001);

    $this->artisan('locations:suggest-coordinates', ['--apply' => true])->assertSuccessful();

    expect((float) $this->location->fresh()->lat)->toBe(21.0);
});

test('skips locations whose suggested shift is below min-shift', function () {
    seedDriverCluster($this);

    $this->artisan('locations:suggest-coordinates', ['--apply' => true, '--min-shift' => 2000])->assertSuccessful();

    expect((float) $this->location->fresh()->lat)->toBe(21.0);
});
