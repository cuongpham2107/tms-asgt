<?php

use App\Enums\DriverWorkShift;
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
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShiftScheduleService;
use Illuminate\Support\Facades\URL;

it('rejects requests without valid X-Api-Key', function (): void {
    $this->getJson('/api/public/vehicles/search?search=3238')
        ->assertUnauthorized();

    $this->withHeader('X-Api-Key', 'wrong-key')
        ->getJson('/api/public/vehicles/search?search=3238')
        ->assertUnauthorized();
});

it('validates minimum search length and matches normalized plate numbers', function (): void {
    $apiKey = (string) config('app.key');

    $this->withHeader('X-Api-Key', $apiKey)
        ->getJson('/api/public/vehicles/search?search=1')
        ->assertUnprocessable();

    $driver = User::factory()->create([
        'name' => 'Nguyễn Đức Thiện',
        'phone' => '0392878817',
        'is_active' => true,
    ]);

    $todayShift = ShiftScheduleService::determineShift();
    $vehicle = Vehicle::create([
        'plate_number' => '20C13238',
        'vehicle_type' => VehicleType::Normal,
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::On,
        'is_active' => true,
        'load_capacity' => 14.10,
        'door_count' => 4,
        'gps_lat' => 21.2187000,
        'gps_lng' => 105.8067000,
        'gps_address' => 'Nội Bài, Hà Nội',
        'last_gps_update' => now(),
        'even_driver_id' => $todayShift === DriverWorkShift::Even ? $driver->id : null,
        'odd_driver_id' => $todayShift === DriverWorkShift::Odd ? $driver->id : null,
    ]);

    foreach (['3238', '20C-132.38', '20c 13238'] as $query) {
        $response = $this->withHeader('X-Api-Key', $apiKey)
            ->getJson('/api/public/vehicles/search?search='.urlencode($query))
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $vehicle->id)
            ->assertJsonPath('data.0.plate_number', '20C13238')
            ->assertJsonPath('data.0.load_capacity_kg', 14100)
            ->assertJsonPath('data.0.door_count', 4)
            ->assertJsonPath('data.0.source', 'scheduled_shift')
            ->assertJsonPath('data.0.driver.name', 'Nguyễn Đức Thiện')
            ->assertJsonPath('data.0.driver.phone', '0392878817');

        expect($response->json('data.0.formatted_plate'))->toBe('20C-132.38')
            ->and($response->json('data.0.copy_text'))
            ->toContain('20C-132.38')
            ->toContain('SĐT: 0392878817')
            ->toContain('Lái xe: Nguyễn Đức Thiện')
            ->toContain('Trọng tải cho phép: 14100 kg')
            ->toContain('Số cửa: 4')
            ->toContain('http');
    }

    $x1Response = $this->withHeader('X-Api-Key', $apiKey)
        ->getJson('/api/public/vehicles/search?search=3238&tobot=x1')
        ->assertOk()
        ->assertJsonPath('tobot', 'x1');

    expect($x1Response->json('data.0.copy_text'))
        ->toContain('20C-132.38')
        ->toContain('Mobile: 0392878817')
        ->toContain('Driver: Nguyễn Đức Thiện')
        ->toContain('Payload: 14,100 kg')
        ->toContain('Num. of doors: 4')
        ->toContain('Key tag: ')
        ->toContain('http');
});

it('prefers nearest active order driver over scheduled even/odd driver', function (): void {
    $apiKey = (string) config('app.key');
    $todayShift = ShiftScheduleService::determineShift();

    $scheduledDriver = User::factory()->create([
        'name' => 'Tài xế theo lịch',
        'phone' => '0900000001',
        'is_active' => true,
    ]);
    $activeOrderDriver = User::factory()->create([
        'name' => 'Tài xế chạy đơn',
        'phone' => '0900000002',
        'is_active' => true,
    ]);

    $vehicle = Vehicle::create([
        'plate_number' => '29E99988',
        'vehicle_type' => VehicleType::Normal,
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::On,
        'is_active' => true,
        'load_capacity' => 5.25,
        'even_driver_id' => $todayShift === DriverWorkShift::Even ? $scheduledDriver->id : null,
        'odd_driver_id' => $todayShift === DriverWorkShift::Odd ? $scheduledDriver->id : null,
    ]);

    $area = Area::create(['name' => 'Nội Bài', 'code' => 'NBA']);
    $customer = Customer::create(['name' => 'KH Test', 'code' => 'KHT', 'is_active' => true]);
    $location = Location::create(['name' => 'Kho A', 'code' => 'KHOA', 'area_id' => $area->id, 'is_active' => true]);

    $trip = Trip::create([
        'trip_code' => 'CD-TEST-ACTIVE',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $activeOrderDriver->id,
        'status' => TripStatus::Delivering,
        'started_at' => now()->subHour(),
    ]);

    Order::create([
        'order_code' => 'ORD-ACTIVE-1',
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'pickup_location_id' => $location->id,
        'planned_loading_at' => now()->addHour(),
        'trip_id' => $trip->id,
        'status' => OrderStatus::InTransit,
    ]);

    $this->withHeader('X-Api-Key', $apiKey)
        ->getJson('/api/public/vehicles/search?search=99988')
        ->assertOk()
        ->assertJsonPath('data.0.source', 'active_order')
        ->assertJsonPath('data.0.driver.name', 'Tài xế chạy đơn')
        ->assertJsonPath('data.0.driver.phone', '0900000002')
        ->assertJsonPath('data.0.active_order.order_code', 'ORD-ACTIVE-1');
});

it('serves signed public vehicle GPS tracking page and rejects unsigned requests', function (): void {
    $vehicle = Vehicle::create([
        'plate_number' => '29H11223',
        'vehicle_type' => VehicleType::Normal,
        'type' => VehicleOwnerType::Company,
        'status' => VehicleStatus::On,
        'is_active' => true,
        'load_capacity' => 8.5,
        'door_count' => 2,
        'gps_lat' => 21.0285000,
        'gps_lng' => 105.8542000,
        'gps_speed' => 38,
        'gps_address' => 'Hà Nội, Việt Nam',
        'last_gps_update' => now(),
    ]);

    $this->get('/vi-tri-xe/'.$vehicle->id)->assertForbidden();

    $relativeSignedUrl = URL::temporarySignedRoute('vehicles.public-tracking', now()->addHour(), ['vehicle' => $vehicle], absolute: false);
    $this->get($relativeSignedUrl.'&zarsrc=30')
        ->assertOk()
        ->assertSee('29H11223')
        ->assertSee('Hà Nội, Việt Nam');

    $positionUrl = URL::temporarySignedRoute('vehicles.public-tracking.position', now()->addHour(), ['vehicle' => $vehicle], absolute: false);
    $this->getJson($positionUrl)
        ->assertOk()
        ->assertJsonPath('has_position', true)
        ->assertJsonPath('position.latitude', 21.0285)
        ->assertJsonPath('position.longitude', 105.8542);
});
