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
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $this->area = Area::create([
        'type' => 'HHHK',
        'code' => 'NORTH',
        'name' => 'North',
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-001',
        'name' => 'Customer 001',
        'is_active' => true,
    ]);
});

test('can bulk assign vehicle and driver to draft orders', function () {
    $driver = User::factory()->create();
    $driver->assignRole($this->driverRole);

    $vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $order1 = Order::create([
        'order_code' => 'ORD-001',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $order2 = Order::create([
        'order_code' => 'ORD-002',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Draft,
        'created_by' => User::factory()->create()->id,
    ]);

    $order3 = Order::create([
        'order_code' => 'ORD-003',
        'type' => 'HHHK',
        'area_id' => $this->area->id,
        'customer_id' => $this->customer->id,
        'status' => OrderStatus::Assigned,
        'created_by' => User::factory()->create()->id,
    ]);

    $admin = User::factory()->create();
    $this->actingAs($admin);

    $trip = Trip::create([
        'trip_code' => Trip::generateTripCode(),
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
    ]);

    foreach ([$order1, $order2] as $i => $order) {
        $order->update([
            'trip_id' => $trip->id,
            'trip_sequence' => $i,
            'status' => OrderStatus::Assigned,
        ]);
    }

    $vehicle->update(['status' => VehicleStatus::Running]);

    $tripCount = Trip::count();
    expect($tripCount)->toBe(1);

    $order1->refresh();
    $order2->refresh();
    $order3->refresh();

    expect($order1->trip_id)->not->toBeNull();
    expect($order1->trip)->not->toBeNull();
    expect($order1->trip->vehicle_id)->toBe($vehicle->id);
    expect($order1->trip->driver_id)->toBe($driver->id);
    expect($order1->status)->toBe(OrderStatus::Assigned);

    expect($order2->trip_id)->not->toBeNull();
    expect($order2->trip)->not->toBeNull();
    expect($order2->trip->vehicle_id)->toBe($vehicle->id);
    expect($order2->trip->driver_id)->toBe($driver->id);
    expect($order2->status)->toBe(OrderStatus::Assigned);

    // Order3 is already Assigned, so it should not be affected by the bulk assign (only draft orders)
    expect($order3->trip_id)->toBeNull();
    expect($order3->trip)->toBeNull();

    $vehicle->refresh();
    expect($vehicle->status)->toBe(VehicleStatus::Running);
});
