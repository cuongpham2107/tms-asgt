<?php

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\Pages\ViewTripTimeline;
use App\Filament\Resources\Trips\Tables\TripsTable;
use App\Filament\Resources\Trips\Widgets\TripStatsOverviewWidget;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    foreach ([
        'ViewAny:Trip', 'View:Trip', 'Create:Trip', 'Update:Trip', 'Delete:Trip',
        'DeleteAny:Trip', 'Restore:Trip', 'RestoreAny:Trip', 'ForceDelete:Trip', 'ForceDeleteAny:Trip',
        'Replicate:Trip', 'Reorder:Trip',
        'Widget:TripStatsOverview',
    ] as $permission) {
        Permission::create(['name' => $permission, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
    }

    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

test('trips list page renders successfully', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '51C-777.77',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    Trip::create([
        'trip_code' => 'TRIP-TEST-1',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
        'start_km' => 100,
    ]);

    Livewire::test(ListTrips::class)
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('trip view timeline page renders successfully', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '51C-777.77',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-TEST-2',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
        'start_km' => 100,
    ]);

    Livewire::test(ViewTripTimeline::class, [
        'record' => $trip->getKey(),
    ])
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('trip stats overview widget renders successfully', function () {
    Livewire::test(TripStatsOverviewWidget::class)
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('trip resolves orders and lists pickups/deliveries correctly', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '51C-777.77',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $driver = User::factory()->create();

    $trip = Trip::create([
        'trip_code' => 'TRIP-TEST-3',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
        'start_km' => 100,
    ]);

    $area = Area::create([
        'code' => 'TEST',
        'name' => 'Test Area',
    ]);

    $customer = Customer::create([
        'code' => 'CUST',
        'name' => 'Test Customer',
    ]);

    $order1 = Order::create([
        'order_code' => 'ORD-1',
        'trip_id' => $trip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'planned_loading_at' => now(),
        'pickup_address' => 'Pickup A',
        'status' => OrderStatus::Sent,
    ]);
    $order1->deliveryPoints()->create([
        'address' => 'Delivery A',
        'sequence' => 1,
    ]);

    $order2 = Order::create([
        'order_code' => 'ORD-2',
        'trip_id' => $trip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'planned_loading_at' => now()->addMinutes(10),
        'pickup_address' => 'Pickup B',
        'status' => OrderStatus::Sent,
    ]);
    $order2->deliveryPoints()->create([
        'address' => 'Delivery B',
        'sequence' => 1,
    ]);

    $order3 = Order::create([
        'order_code' => 'ORD-3',
        'trip_id' => $trip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'planned_loading_at' => now()->addMinutes(20),
        'pickup_address' => 'Pickup C',
        'status' => OrderStatus::Sent,
    ]);
    $order3->deliveryPoints()->create([
        'address' => 'Delivery C',
        'sequence' => 1,
    ]);

    $trip->load('orders.pickupLocation', 'orders.deliveryPoints');

    expect($trip->orders->count())->toBe(3);
    expect($trip->orders->pluck('order_code')->toArray())->toBe(['ORD-1', 'ORD-2', 'ORD-3']);

    $pickups = TripsTable::getPickupLocations($trip);
    $deliveries = TripsTable::getDeliveryDestination($trip);

    expect($pickups)->toBe('Pickup A → Pickup B → Pickup C');
    expect($deliveries)->toBe('Delivery A → Delivery B → Delivery C');
});

test('trips list shows pending trip when no date filter applied', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '51P-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    Trip::create([
        'trip_code' => 'TRIP-PENDING-1',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
        'started_at' => null,
    ]);

    Livewire::test(ListTrips::class)
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->assertSee('51P-123.45');
});
