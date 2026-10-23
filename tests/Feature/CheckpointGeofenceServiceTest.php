<?php

use App\Enums\CheckpointType;
use App\Enums\GeoCheckStatus;
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
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Services\Trip\CheckpointGeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// 0.001° vĩ độ ≈ 111m: +0.003 ≈ 333m (trong 500m), +0.006 ≈ 667m (ngoài 500m).
const GEO_NEAR = 0.003;
const GEO_FAR = 0.006;

beforeEach(function () {
    $this->freezeTime();

    $driver = User::factory()->create();
    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-00001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 1000,
        'gps_lat' => 21.0,
        'gps_lng' => 105.0,
        'gps_speed' => 0,
        'car_status' => 0,
        'last_gps_update' => now()->subMinute(),
    ]);
    $this->trip = Trip::create([
        'trip_code' => 'CD-TEST-001',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Started,
    ]);

    $this->pickup = Location::create(['code' => 'PICK', 'name' => 'Kho lấy', 'lat' => 21.0, 'lng' => 105.0, 'loc_type' => 'pickup', 'is_active' => true]);
    $this->delivery = Location::create(['code' => 'DROP', 'name' => 'Kho giao', 'lat' => 21.5, 'lng' => 105.5, 'loc_type' => 'delivery', 'is_active' => true]);

    $area = Area::create(['type' => OrderType::Hhhk, 'code' => 'TEST', 'name' => 'Test Area']);
    $customer = Customer::create(['code' => 'CUST-TEST', 'name' => 'Test Customer', 'is_active' => true]);
    $this->order = Order::create([
        'order_code' => 'ORD-TEST-001',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'trip_id' => $this->trip->id,
        'pickup_location_id' => $this->pickup->id,
        'status' => OrderStatus::Sent,
        'created_by' => $driver->id,
    ]);
    $this->deliveryPoint = OrderDeliveryPoint::create([
        'order_id' => $this->order->id,
        'location_id' => $this->delivery->id,
        'sequence' => 1,
        'address' => 'Kho giao',
        'status' => 'pending',
    ]);

    $this->check = fn (CheckpointType $type, array $payload = []) => app(CheckpointGeofenceService::class)
        ->check($this->trip->fresh(), $type, ['order_id' => $this->order->id, ...$payload]);
});

// ---- Lớp A: GPS điện thoại ↔ xe (EUP) ----

test('driver within radius of fresh EUP position is valid', function () {
    $result = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0 + GEO_NEAR, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::Valid)
        ->and($result->vehicleDistanceM)->toBeBetween(300, 370)
        ->and((float) $result->eupLat)->toBe(21.0);
});

test('driver beyond radius of fresh EUP position is out of range', function () {
    $result = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::OutOfRange);
});

test('stale EUP position of a running vehicle cannot verify the driver', function () {
    $this->vehicle->update(['last_gps_update' => now()->subMinutes(6), 'car_status' => 0]);

    $result = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::Unverified)
        ->and($result->eupLat)->toBeNull();
});

test('stale EUP position is still used when the engine is off', function () {
    $this->vehicle->update(['last_gps_update' => now()->subHours(2), 'car_status' => 2]);

    $result = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::OutOfRange);
});

test('stale EUP position is still used when the vehicle is stopped with the engine running', function () {
    $this->vehicle->update(['last_gps_update' => now()->subHours(2), 'car_status' => 10]);

    $result = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::OutOfRange);
});

test('EUP with lost GPS signal cannot verify the driver', function () {
    $this->vehicle->update(['car_status' => 5]);

    $result = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::Unverified);
});

test('missing phone GPS cannot verify the driver', function () {
    $result = ($this->check)(CheckpointType::Started);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::Unverified)
        ->and((float) $result->eupLat)->toBe(21.0);
});

test('vehicle without EUP cannot verify the driver', function () {
    $this->vehicle->update(['gps_lat' => null, 'gps_lng' => null, 'last_gps_update' => null, 'car_status' => null]);

    $result = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::Unverified);
});

// ---- Lớp B: xe ↔ location ----

test('pickup checkpoints compare the vehicle with the order pickup location', function () {
    $this->vehicle->update(['gps_lat' => 21.0 + GEO_NEAR]);

    $arrived = ($this->check)(CheckpointType::ArrivedPickup, ['gps_lat' => 21.0 + GEO_NEAR, 'gps_lng' => 105.0]);
    $left = ($this->check)(CheckpointType::LeftPickup, ['gps_lat' => 21.0 + GEO_NEAR, 'gps_lng' => 105.0]);

    expect($arrived->locationStatus)->toBe(GeoCheckStatus::Valid)
        ->and($arrived->locationDistanceM)->toBeBetween(300, 370)
        ->and($left->locationStatus)->toBe(GeoCheckStatus::Valid);
});

test('vehicle far from the location is out of range even when the phone is near', function () {
    $this->vehicle->update(['gps_lat' => 21.0 + GEO_FAR]);

    $result = ($this->check)(CheckpointType::ArrivedPickup, ['gps_lat' => 21.0, 'gps_lng' => 105.0]);

    expect($result->locationStatus)->toBe(GeoCheckStatus::OutOfRange);
});

test('without fresh EUP the phone position is compared with the location', function () {
    $this->vehicle->update(['last_gps_update' => now()->subHour()]);

    $near = ($this->check)(CheckpointType::ArrivedPickup, ['gps_lat' => 21.0 + GEO_NEAR, 'gps_lng' => 105.0]);
    $far = ($this->check)(CheckpointType::ArrivedPickup, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);
    $none = ($this->check)(CheckpointType::ArrivedPickup);

    expect($near->locationStatus)->toBe(GeoCheckStatus::Valid)
        ->and($far->locationStatus)->toBe(GeoCheckStatus::OutOfRange)
        ->and($none->locationStatus)->toBe(GeoCheckStatus::Unverified);
});

test('delivery checkpoints compare with the delivery point or the driver-chosen location', function () {
    $this->vehicle->update(['gps_lat' => 21.5, 'gps_lng' => 105.5]);
    $chosen = Location::create(['code' => 'NEW', 'name' => 'Điểm mới', 'lat' => 22.0, 'lng' => 106.0, 'loc_type' => 'delivery', 'is_active' => true]);

    $arrived = ($this->check)(CheckpointType::ArrivedDelivery, ['delivery_point_id' => $this->deliveryPoint->id]);
    $completed = ($this->check)(CheckpointType::Completed, ['delivery_point_id' => $this->deliveryPoint->id]);
    $newLocation = ($this->check)(CheckpointType::ArrivedDelivery, ['new_delivery_location_id' => $chosen->id]);

    expect($arrived->locationStatus)->toBe(GeoCheckStatus::Valid)
        ->and($completed->locationStatus)->toBe(GeoCheckStatus::Valid)
        ->and($newLocation->locationStatus)->toBe(GeoCheckStatus::OutOfRange);
});

test('location without coordinates is skipped', function () {
    $this->pickup->update(['lat' => null, 'lng' => null]);

    $result = ($this->check)(CheckpointType::ArrivedPickup, ['gps_lat' => 21.0, 'gps_lng' => 105.0]);

    expect($result->locationStatus)->toBe(GeoCheckStatus::Skipped)
        ->and($result->locationDistanceM)->toBeNull();
});

test('started and end checkpoints skip the location layer', function () {
    $started = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0, 'gps_lng' => 105.0]);
    $end = ($this->check)(CheckpointType::End, ['gps_lat' => 21.0, 'gps_lng' => 105.0]);

    expect($started->locationStatus)->toBe(GeoCheckStatus::Skipped)
        ->and($end->locationStatus)->toBe(GeoCheckStatus::Skipped);
});

test('driver swap checkpoints are not checked', function () {
    $result = ($this->check)(CheckpointType::DriverSwap, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::Skipped)
        ->and($result->locationStatus)->toBe(GeoCheckStatus::Skipped)
        ->and($result->rejectionMessage(true, true))->toBeNull();
});

// ---- Dùng lộ trình EUP (vehicle_gps_logs) ----

function logVehiclePoint(Vehicle $vehicle, float $lat, int $minutesAgo): void
{
    VehicleGpsLog::create([
        'vehicle_id' => $vehicle->id,
        'lat' => $lat,
        'lng' => 105.0,
        'recorded_at' => now()->subMinutes($minutesAgo),
    ]);
}

test('arrival is valid when the vehicle entered the area in the last 5 minutes', function () {
    $this->vehicle->update(['gps_lat' => 21.0 + GEO_FAR]);
    logVehiclePoint($this->vehicle, 21.0 + GEO_NEAR, 2);
    logVehiclePoint($this->vehicle, 21.0 + GEO_FAR, 0);

    $result = ($this->check)(CheckpointType::ArrivedPickup, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    expect($result->locationStatus)->toBe(GeoCheckStatus::Valid)
        ->and($result->locationDistanceM)->toBeBetween(300, 370);
});

test('leaving is valid when the vehicle was in the area in the last 10 minutes but not earlier only', function () {
    $this->vehicle->update(['gps_lat' => 21.0 + GEO_FAR]);
    logVehiclePoint($this->vehicle, 21.0 + GEO_NEAR, 8);
    logVehiclePoint($this->vehicle, 21.0 + GEO_FAR, 0);

    $recent = ($this->check)(CheckpointType::LeftPickup, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    VehicleGpsLog::query()->delete();
    logVehiclePoint($this->vehicle, 21.0 + GEO_NEAR, 15);
    logVehiclePoint($this->vehicle, 21.0 + GEO_FAR, 5);
    logVehiclePoint($this->vehicle, 21.0 + GEO_FAR, 0);

    $tooOld = ($this->check)(CheckpointType::LeftPickup, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    expect($recent->locationStatus)->toBe(GeoCheckStatus::Valid)
        ->and($tooOld->locationStatus)->toBe(GeoCheckStatus::OutOfRange);
});

test('arrival is out of range when the vehicle stayed far away during the window', function () {
    $this->vehicle->update(['gps_lat' => 21.0 + GEO_FAR]);
    foreach ([4, 2, 0] as $minutesAgo) {
        logVehiclePoint($this->vehicle, 21.0 + GEO_FAR, $minutesAgo);
    }

    $result = ($this->check)(CheckpointType::ArrivedPickup, ['gps_lat' => 21.0, 'gps_lng' => 105.0]);

    expect($result->locationStatus)->toBe(GeoCheckStatus::OutOfRange);
});

test('driver check uses the logged vehicle point closest to the submission time', function () {
    // Bảng vehicles đang giữ điểm cũ ở xa, lộ trình có điểm 1 phút trước ngay cạnh tài xế.
    $this->vehicle->update(['gps_lat' => 21.0 + GEO_FAR, 'last_gps_update' => now()->subMinutes(10)]);
    logVehiclePoint($this->vehicle, 21.0, 1);

    $result = ($this->check)(CheckpointType::Started, ['gps_lat' => 21.0, 'gps_lng' => 105.0]);

    expect($result->vehicleStatus)->toBe(GeoCheckStatus::Valid)
        ->and((float) $result->eupLat)->toBe(21.0);
});

test('without logs in the window the current vehicle position is used', function () {
    logVehiclePoint($this->vehicle, 21.0 + GEO_NEAR, 30);
    $this->vehicle->update(['gps_lat' => 21.0 + GEO_FAR]);

    $result = ($this->check)(CheckpointType::ArrivedPickup, ['gps_lat' => 21.0 + GEO_FAR, 'gps_lng' => 105.0]);

    expect($result->locationStatus)->toBe(GeoCheckStatus::OutOfRange);
});
