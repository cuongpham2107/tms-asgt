<?php

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

    $this->driver = User::factory()->create(['name' => 'Driver One']);
    $this->driver->assignRole($this->driverRole);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-999.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 50000,
    ]);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NBA',
        'name' => 'Noi Bai Area',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-01',
        'name' => 'Customer A',
        'is_active' => true,
    ]);
});

test('trip active returns trips sorted fifo for pending trips and includes sent_at', function () {
    Sanctum::actingAs($this->driver);

    // Trip 1 created earlier, sent at 08:00
    $trip1 = Trip::create([
        'trip_code' => 'TRIP-FIFO-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'created_at' => Carbon::parse('2026-10-21 07:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-01',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip1->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
        'sent_at' => Carbon::parse('2026-10-21 08:00:00'),
    ]);

    // Trip 2 created later, sent at 09:30
    $trip2 = Trip::create([
        'trip_code' => 'TRIP-FIFO-02',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'created_at' => Carbon::parse('2026-10-21 07:30:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-02',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip2->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
        'sent_at' => Carbon::parse('2026-10-21 09:30:00'),
    ]);

    $res = $this->getJson('/api/driver/trips/active')->assertSuccessful();
    $data = $res->json('data');

    expect($data)->toHaveCount(2);
    // Earlier sent_at (08:00) should be first
    expect($data[0]['id'])->toBe($trip1->id);
    expect($data[0]['sent_at'])->toBe(Carbon::parse('2026-10-21 08:00:00')->toIso8601String());

    // Later sent_at (09:30) should be second
    expect($data[1]['id'])->toBe($trip2->id);
    expect($data[1]['sent_at'])->toBe(Carbon::parse('2026-10-21 09:30:00')->toIso8601String());
});

test('trip active puts running trip before pending trip regardless of sent_at', function () {
    Sanctum::actingAs($this->driver);

    // Trip 1 is pending, sent earlier at 08:00
    $trip1 = Trip::create([
        'trip_code' => 'TRIP-PENDING-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
        'created_at' => Carbon::parse('2026-10-21 07:00:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-PENDING-01',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip1->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
        'sent_at' => Carbon::parse('2026-10-21 08:00:00'),
    ]);

    // Trip 2 is currently started (active), sent later at 09:00
    $trip2 = Trip::create([
        'trip_code' => 'TRIP-STARTED-02',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
        'started_at' => Carbon::parse('2026-10-21 09:15:00'),
        'created_at' => Carbon::parse('2026-10-21 08:50:00'),
    ]);

    Order::create([
        'order_code' => 'ORD-STARTED-02',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip2->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->driver->id,
        'sent_at' => Carbon::parse('2026-10-21 09:00:00'),
    ]);

    $res = $this->getJson('/api/driver/trips/active')->assertSuccessful();
    $data = $res->json('data');

    expect($data)->toHaveCount(2);
    // Started trip must be first
    expect($data[0]['id'])->toBe($trip2->id);
    // Pending trip must be second
    expect($data[1]['id'])->toBe($trip1->id);
});

test('orders index sorts sent orders by sent_at fifo', function () {
    Sanctum::actingAs($this->driver);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ORDERS-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);

    $ord2 = Order::create([
        'order_code' => 'ORD-LATER',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
        'sent_at' => Carbon::parse('2026-10-21 10:00:00'),
        'created_at' => Carbon::parse('2026-10-21 08:00:00'),
    ]);

    $ord1 = Order::create([
        'order_code' => 'ORD-EARLIER',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'trip_id' => $trip->id,
        'status' => OrderStatus::Sent,
        'created_by' => $this->driver->id,
        'sent_at' => Carbon::parse('2026-10-21 08:30:00'),
        'created_at' => Carbon::parse('2026-10-21 08:10:00'),
    ]);

    $res = $this->getJson('/api/driver/orders')->assertSuccessful();
    $data = $res->json('data');

    expect($data)->toHaveCount(2);
    expect($data[0]['id'])->toBe($ord1->id);
    expect($data[0]['sent_at'])->toBe(Carbon::parse('2026-10-21 08:30:00')->toIso8601String());
    expect($data[1]['id'])->toBe($ord2->id);
    expect($data[1]['sent_at'])->toBe(Carbon::parse('2026-10-21 10:00:00')->toIso8601String());
});
