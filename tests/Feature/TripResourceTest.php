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

    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->assertSee('51P-123.45');
});

test('trips list hides completed and cancelled trips by default and only shows them when status filter is selected', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '51P-999.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $pendingTrip = Trip::create([
        'trip_code' => 'TRIP-PENDING-ACTIVE',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
        'started_at' => null,
    ]);

    $completedTrip = Trip::create([
        'trip_code' => 'TRIP-COMPLETED-HIDDEN',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subHours(2),
    ]);

    $cancelledTrip = Trip::create([
        'trip_code' => 'TRIP-CANCELLED-HIDDEN',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Cancelled,
        'started_at' => now()->subHours(3),
    ]);

    // Default view: shows pending, hides completed & cancelled
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$pendingTrip])
        ->assertCanNotSeeTableRecords([$completedTrip, $cancelledTrip]);

    // Filter by completed: shows completed, hides pending & cancelled
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])
        ->call('filterStatus', 'completed')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$completedTrip])
        ->assertCanNotSeeTableRecords([$pendingTrip, $cancelledTrip]);

    // Filter by cancelled: shows cancelled, hides pending & completed
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])
        ->call('filterStatus', 'cancelled')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$cancelledTrip])
        ->assertCanNotSeeTableRecords([$pendingTrip, $completedTrip]);
});

test('trips list filters by order area place correctly', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '29C-888.88',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $driver = User::factory()->create();

    $areaNba = Area::create([
        'code' => 'NBA',
        'name' => 'Nội Bài',
    ]);

    $areaHni = Area::create([
        'code' => 'HNI',
        'name' => 'Hà Nội',
    ]);

    $customer = Customer::create([
        'code' => 'CUST-AREA',
        'name' => 'Customer Area',
    ]);

    $tripNba = Trip::create([
        'trip_code' => 'TRIP-NBA-1',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
    ]);

    Order::create([
        'order_code' => 'ORD-NBA-1',
        'trip_id' => $tripNba->id,
        'area_id' => $areaNba->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'pickup_address' => 'Nội Bài Airport',
        'status' => OrderStatus::Assigned,
    ]);

    $tripHni = Trip::create([
        'trip_code' => 'TRIP-HNI-1',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
    ]);

    Order::create([
        'order_code' => 'ORD-HNI-1',
        'trip_id' => $tripHni->id,
        'area_id' => $areaHni->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'pickup_address' => 'Hà Nội Center',
        'status' => OrderStatus::Assigned,
    ]);

    // When filtering by NBA
    Livewire::test(ListTrips::class, ['orderType' => 'all'])
        ->call('filterPlace', 'NBA')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$tripNba])
        ->assertCanNotSeeTableRecords([$tripHni]);

    // When filtering by HNI
    Livewire::test(ListTrips::class, ['orderType' => 'all'])
        ->call('filterPlace', 'HNI')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$tripHni])
        ->assertCanNotSeeTableRecords([$tripNba]);

    // When filtering by all
    Livewire::test(ListTrips::class, ['orderType' => 'all'])
        ->call('filterPlace', 'all')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$tripNba, $tripHni]);
});

test('trip stat cards calculate correct metrics and filter by status properly', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '29C-999.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $driver = User::factory()->create();
    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài']);
    $customer = Customer::create(['code' => 'CUST-STAT', 'name' => 'Customer Stat']);

    // 1. Unsent trip (Pending with Assigned order)
    $unsentTrip = Trip::create([
        'trip_code' => 'TRIP-STAT-UNSENT',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
        'started_at' => now(),
    ]);
    Order::create([
        'order_code' => 'ORD-STAT-UNSENT',
        'trip_id' => $unsentTrip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'status' => OrderStatus::Assigned,
    ]);

    // 2. Running trip (Started)
    $runningTrip = Trip::create([
        'trip_code' => 'TRIP-STAT-RUNNING',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
    ]);
    Order::create([
        'order_code' => 'ORD-STAT-RUNNING',
        'trip_id' => $runningTrip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'status' => OrderStatus::InTransit,
    ]);

    // 3. Completed trip
    $completedTrip = Trip::create([
        'trip_code' => 'TRIP-STAT-COMPLETED',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHour(),
    ]);

    // 4. Delayed trip (Started with planned_loading_at in past)
    $delayedTrip = Trip::create([
        'trip_code' => 'TRIP-STAT-DELAYED',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Delivering,
        'started_at' => now(),
    ]);
    Order::create([
        'order_code' => 'ORD-STAT-DELAYED',
        'trip_id' => $delayedTrip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'status' => OrderStatus::InTransit,
        'planned_loading_at' => now()->subHours(3),
    ]);

    $component = Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all']);

    /** @var ListTrips $instance */
    $instance = $component->instance();
    $stats = $instance->getTripStats();

    $statsByKey = collect($stats)->keyBy('key');

    expect($statsByKey->has('all'))->toBeTrue();
    expect($statsByKey->get('all')['label'])->toBe('Tổng chuyến');
    expect($statsByKey->get('all')['value'])->toBe(4);

    expect($statsByKey->has('unsent'))->toBeTrue();
    expect($statsByKey->get('unsent')['label'])->toBe('Chưa gửi');
    expect($statsByKey->get('unsent')['value'])->toBe(1);

    expect($statsByKey->has('running'))->toBeTrue();
    expect($statsByKey->get('running')['label'])->toBe('Đang chạy');
    expect($statsByKey->get('running')['value'])->toBe(2); // runningTrip + delayedTrip

    expect($statsByKey->has('completed'))->toBeTrue();
    expect($statsByKey->get('completed')['label'])->toBe('Hoàn thành');
    expect($statsByKey->get('completed')['value'])->toBe(1);

    expect($statsByKey->has('delayed'))->toBeTrue();
    expect($statsByKey->get('delayed')['label'])->toBe('Trễ giờ');
    expect($statsByKey->get('delayed')['value'])->toBe(1);

    // Test filtering by 'unsent'
    $component->call('filterStatus', 'unsent')
        ->assertCanSeeTableRecords([$unsentTrip])
        ->assertCanNotSeeTableRecords([$runningTrip, $completedTrip, $delayedTrip]);

    // Test filtering by 'running'
    $component->call('filterStatus', 'running')
        ->assertCanSeeTableRecords([$runningTrip, $delayedTrip])
        ->assertCanNotSeeTableRecords([$unsentTrip, $completedTrip]);

    // Test filtering by 'delayed'
    $component->call('filterStatus', 'delayed')
        ->assertCanSeeTableRecords([$delayedTrip])
        ->assertCanNotSeeTableRecords([$unsentTrip, $runningTrip, $completedTrip]);
});
