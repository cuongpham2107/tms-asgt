<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Filament\Resources\Trips\Tables\TripsTable;
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

test('buildGpsMapConfig sets autoCenter to false and includes vehicle marker', function () {
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29B-99999',
        'gps_lat' => 21.0285,
        'gps_lng' => 105.8542,
        'gps_speed' => 45.5,
        'gps_address' => 'Hoàn Kiếm, Hà Nội',
    ]);

    $driver = User::factory()->create(['name' => 'Nguyễn Văn A', 'phone' => '0901234567']);

    $trip = Trip::create([
        'trip_code' => 'TRIP-TEST-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Started,
    ]);

    $reflection = new ReflectionClass(TripsTable::class);
    $method = $reflection->getMethod('buildGpsMapConfig');
    $method->setAccessible(true);

    $config = $method->invoke(null, $trip);

    expect($config['autoCenter'])->toBeFalse()
        ->and($config['defaultCoord'])->toBe([21.0285, 105.8542])
        ->and($config['mapHeight'])->toBe(450);

    // Should have vehicle marker and vehicle pulse circle
    $layers = collect($config['layersData']);
    $vehicleMarker = $layers->first(fn ($l) => ($l['id'] ?? '') === 'gps-vehicle-'.$trip->id);
    expect($vehicleMarker)->not->toBeNull()
        ->and($vehicleMarker['tooltip']['content'] ?? '')->toContain('29B-99999');

    $pulseMarker = $layers->first(fn ($l) => ($l['id'] ?? '') === 'vehicle-pulse-'.$trip->id);
    expect($pulseMarker)->not->toBeNull();
});

test('buildGpsMapConfig builds route points, OSRM polylines, and sets fitBounds to true', function () {
    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài']);

    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-12345',
        'gps_lat' => 21.0350,
        'gps_lng' => 105.8400,
    ]);

    $locStart = Location::create([
        'code' => 'KHO-DI',
        'name' => 'Kho Đi Hà Nội',
        'lat' => 21.0200,
        'lng' => 105.8300,
        'is_active' => true,
        'area_id' => $area->id,
    ]);

    $locPickup = Location::create([
        'code' => 'KHO-PICKUP',
        'name' => 'Điểm Lấy Hàng',
        'lat' => 21.0400,
        'lng' => 105.8500,
        'is_active' => true,
        'area_id' => $area->id,
    ]);

    $locDelivery = Location::create([
        'code' => 'KHO-DELIVERY',
        'name' => 'Điểm Giao Hàng',
        'lat' => 21.0600,
        'lng' => 105.8700,
        'is_active' => true,
        'area_id' => $area->id,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ROUTE-002',
        'vehicle_id' => $vehicle->id,
        'start_location_id' => $locStart->id,
        'status' => TripStatus::Started,
    ]);

    $customer = Customer::create(['code' => 'CUST01', 'name' => 'Khách A']);

    $user = User::factory()->create();

    $order = Order::create([
        'order_code' => 'ORD-001',
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'trip_id' => $trip->id,
        'pickup_location_id' => $locPickup->id,
        'status' => OrderStatus::InTransit,
    ]);

    OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => $locDelivery->id,
        'sequence' => 1,
        'address' => 'Số 10 Cầu Giấy, Hà Nội',
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'gps_lat' => 21.0405,
        'gps_lng' => 105.8505,
        'occurred_at' => now(),
    ]);

    $reflection = new ReflectionClass(TripsTable::class);
    $method = $reflection->getMethod('buildGpsMapConfig');
    $method->setAccessible(true);

    $config = $method->invoke(null, $trip);

    expect($config['autoCenter'])->toBeFalse()
        ->and($config['fitBounds'])->toBeTrue();

    $layers = collect($config['layersData']);

    // Check Start marker
    $startMarker = $layers->first(fn ($l) => ($l['id'] ?? '') === 'start-'.$trip->id);
    expect($startMarker)->not->toBeNull()
        ->and($startMarker['tooltip']['content'] ?? '')->toContain('Kho Đi Hà Nội');

    // Check Pickup marker
    $pickupMarker = $layers->first(fn ($l) => ($l['id'] ?? '') === 'pickup-order-'.$order->id);
    expect($pickupMarker)->not->toBeNull()
        ->and($pickupMarker['tooltip']['content'] ?? '')->toContain('Điểm Lấy Hàng');

    // Check Delivery marker
    $deliveryMarker = $layers->first(fn ($l) => str_starts_with($l['id'] ?? '', 'delivery-dp-'));
    expect($deliveryMarker)->not->toBeNull();

    // Check Checkpoint circle
    $checkpointCircle = $layers->first(fn ($l) => str_starts_with($l['id'] ?? '', 'checkpoint-'));
    expect($checkpointCircle)->not->toBeNull();

    // Check polyline layers
    $plannedPolyline = $layers->first(fn ($l) => ($l['id'] ?? '') === 'route-planned-'.$trip->id);
    expect($plannedPolyline)->not->toBeNull();
});
