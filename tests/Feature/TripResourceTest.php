<?php

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Trips\Pages\ViewTripTimeline;
use App\Filament\Resources\Trips\Schemas\TripForm;
use App\Filament\Resources\Trips\Tables\TripsTable;
use App\Filament\Resources\Trips\Widgets\TripStatsOverviewWidget;
use App\Http\Resources\TripResource;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Export\TripExcelExportService;
use App\Services\Trip\DeliveryProgressSyncService;
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

    Livewire::test(ListTrips::class)->loadTable()
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

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Completed,
        'km_reading' => 150.5,
        'occurred_at' => now(),
    ]);

    Livewire::test(ViewTripTimeline::class, [
        'record' => $trip->getKey(),
    ])
        ->assertStatus(200)
        ->assertHasNoErrors()
        ->assertSee('150,5 km');
});

test('trip stats overview widget renders successfully', function () {
    Livewire::test(TripStatsOverviewWidget::class)
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('trip lists pickup and delivery points of its order in order', function () {
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

    $order = Order::create([
        'order_code' => 'ORD-1',
        'trip_id' => $trip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'planned_loading_at' => now(),
        'pickup_address' => 'Pickup A',
        'status' => OrderStatus::Sent,
    ]);
    foreach (['Delivery A', 'Delivery B', 'Delivery C'] as $sequence => $address) {
        $order->deliveryPoints()->create(['address' => $address, 'sequence' => $sequence + 1]);
    }

    $trip->load('orders.pickupLocation', 'orders.deliveryPoints');

    expect($trip->orders->pluck('order_code')->toArray())->toBe(['ORD-1']);

    expect(TripsTable::getPickupLocations($trip))->toBe('Pickup A');
    expect(TripsTable::getDeliveryDestination($trip))->toBe('Delivery A → Delivery B → Delivery C');
});

test('TripsTable and TripResource preserve repeated locations in route (ALSB -> ACSV -> ALSC -> ACSV)', function () {
    $area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'TEST-AREA-ABA',
        'name' => 'Test Area ABA',
    ]);

    $vehicle = Vehicle::create([
        'plate_number' => '51P-ABA.01',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $driver = User::factory()->create(['name' => 'Driver ABA']);

    $locPickup = Location::create([
        'code' => 'ALSB',
        'name' => 'ALSB',
        'loc_type' => 'pickup',
        'is_active' => true,
    ]);

    $locACSV = Location::create([
        'code' => 'ACSV',
        'name' => 'ACSV',
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $locALSC = Location::create([
        'code' => 'ALSC',
        'name' => 'ALSC',
        'loc_type' => 'delivery',
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ABA-001',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => TripStatus::Pending,
        'start_location_id' => $locPickup->id,
        'end_location_id' => $locACSV->id,
    ]);

    $customer = Customer::create([
        'code' => 'CUST-ABA',
        'name' => 'Customer ABA',
    ]);

    $order = Order::create([
        'order_code' => 'ASG-3',
        'trip_id' => $trip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'planned_loading_at' => now(),
        'pickup_location_id' => $locPickup->id,
        'status' => OrderStatus::Sent,
    ]);

    $order->deliveryPoints()->createMany([
        [
            'location_id' => $locACSV->id,
            'sequence' => 1,
            'status' => 'pending',
        ],
        [
            'location_id' => $locALSC->id,
            'sequence' => 2,
            'status' => 'pending',
        ],
        [
            'location_id' => $locACSV->id,
            'sequence' => 3,
            'status' => 'pending',
        ],
    ]);

    $trip->load(['orders.pickupLocation', 'orders.deliveryPoints.location', 'startLocation', 'endLocation']);

    $pickups = TripsTable::getPickupLocations($trip);
    $deliveries = TripsTable::getDeliveryDestination($trip);

    expect($pickups)->toBe('ALSB');
    expect($deliveries)->toBe('ACSV → ALSC → ACSV');

    // Test API resource
    $resource = TripResource::make($trip)->resolve();
    expect($resource['route'])->toBe('ALSB → ACSV → ALSC → ACSV');
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

    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])->loadTable()
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

    $deliveredTrip = Trip::create([
        'trip_code' => 'TRIP-DELIVERED-HIDDEN',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Delivered,
        'started_at' => now()->subHours(1),
    ]);

    // Default view: shows pending, hides completed, delivered & cancelled
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])->loadTable()
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$pendingTrip])
        ->assertCanNotSeeTableRecords([$completedTrip, $deliveredTrip, $cancelledTrip]);

    // Filter by running: shows running/pending-running, does not show delivered
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])->loadTable()
        ->call('filterStatus', 'running')
        ->assertStatus(200)
        ->assertCanNotSeeTableRecords([$deliveredTrip, $completedTrip, $cancelledTrip]);

    // Filter by completed: shows completed & delivered, hides pending & cancelled
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])->loadTable()
        ->call('filterStatus', 'completed')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$completedTrip, $deliveredTrip])
        ->assertCanNotSeeTableRecords([$pendingTrip, $cancelledTrip]);

    // Filter by cancelled: shows cancelled, hides pending, delivered & completed
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])->loadTable()
        ->call('filterStatus', 'cancelled')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$cancelledTrip])
        ->assertCanNotSeeTableRecords([$pendingTrip, $completedTrip, $deliveredTrip]);
});

test('trips list filters by km_report status correctly', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '51P-555.55',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $driver = User::factory()->create();

    $kmReportTrip = Trip::create([
        'trip_code' => 'TRIP-KM-REPORT',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Delivering,
        'started_at' => now(),
        'is_paused' => true,
    ]);

    TripKmReport::create([
        'trip_id' => $kmReportTrip->id,
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'reported_km' => 150.0,
        'system_km' => 100.0,
        'status' => TripKmReportStatus::Pending,
    ]);

    $normalTrip = Trip::create([
        'trip_code' => 'TRIP-NORMAL',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Delivering,
        'started_at' => now(),
    ]);

    $resolvedTrip = Trip::create([
        'trip_code' => 'TRIP-RESOLVED',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Delivering,
        'started_at' => now(),
    ]);

    TripKmReport::create([
        'trip_id' => $resolvedTrip->id,
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'reported_km' => 120.0,
        'system_km' => 100.0,
        'status' => TripKmReportStatus::Resolved,
    ]);

    $component = Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])->loadTable();

    expect($component->instance()->getTripStatusCount('km_report'))->toBe(1);

    $component
        ->call('filterStatus', 'km_report')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$kmReportTrip])
        ->assertCanNotSeeTableRecords([$normalTrip, $resolvedTrip]);
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
        'status' => OrderStatus::Sent,
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
        'status' => OrderStatus::Sent,
    ]);

    // When filtering by NBA
    Livewire::test(ListTrips::class, ['orderType' => 'all'])->loadTable()
        ->call('filterPlace', 'NBA')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$tripNba])
        ->assertCanNotSeeTableRecords([$tripHni]);

    // When filtering by HNI
    Livewire::test(ListTrips::class, ['orderType' => 'all'])->loadTable()
        ->call('filterPlace', 'HNI')
        ->assertStatus(200)
        ->assertCanSeeTableRecords([$tripHni])
        ->assertCanNotSeeTableRecords([$tripNba]);

    // When filtering by all
    Livewire::test(ListTrips::class, ['orderType' => 'all'])->loadTable()
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

    // 1. Pending trip (Pending with Sent order)
    $pendingTrip = Trip::create([
        'trip_code' => 'TRIP-STAT-PENDING',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
        'started_at' => now(),
    ]);
    Order::create([
        'order_code' => 'ORD-STAT-PENDING',
        'trip_id' => $pendingTrip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $driver->id,
        'status' => OrderStatus::Sent,
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

    $component = Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all'])->loadTable();

    /** @var ListTrips $instance */
    $instance = $component->instance();
    $stats = $instance->getTripStats();

    $statsByKey = collect($stats)->keyBy('key');

    expect($statsByKey->has('all'))->toBeTrue();
    expect($statsByKey->get('all')['label'])->toBe('Tổng chuyến');
    expect($statsByKey->get('all')['value'])->toBe(4);

    expect($statsByKey->has('pending'))->toBeTrue();
    expect($statsByKey->get('pending')['label'])->toBe('Chờ chạy');
    expect($statsByKey->get('pending')['value'])->toBe(1);

    expect($statsByKey->has('running'))->toBeTrue();
    expect($statsByKey->get('running')['label'])->toBe('Đang chạy');
    expect($statsByKey->get('running')['value'])->toBe(2); // runningTrip + delayedTrip

    expect($statsByKey->has('completed'))->toBeTrue();
    expect($statsByKey->get('completed')['label'])->toBe('Hoàn thành');
    expect($statsByKey->get('completed')['value'])->toBe(1);

    expect($statsByKey->has('delayed'))->toBeFalse();

    // Test filtering by 'pending'
    $component->call('filterStatus', 'pending')
        ->assertCanSeeTableRecords([$pendingTrip])
        ->assertCanNotSeeTableRecords([$runningTrip, $completedTrip, $delayedTrip]);

    // Test filtering by 'running'
    $component->call('filterStatus', 'running')
        ->assertCanSeeTableRecords([$runningTrip, $delayedTrip])
        ->assertCanNotSeeTableRecords([$pendingTrip, $completedTrip]);
});

test('trips list handles map click and layer events without throwing MethodNotFoundException', function () {
    Livewire::test(ListTrips::class)->loadTable()
        ->call('handleLayerClick', 'layer-123')
        ->call('handleMapClick', 10.8231, 106.6297)
        ->call('handleLayerUpdated', 'layer-123', ['lat' => 10.8231, 'lng' => 106.6297])
        ->assertStatus(200)
        ->assertHasNoErrors();
});

test('trips table does not have manual recalculate_km action', function () {
    Livewire::test(ListTrips::class)->loadTable()
        ->assertTableActionDoesNotExist('recalculate_km');
});

test('ListTrips resets filters and displays target trip when tableActionRecord is present', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '29C-999.99',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-RESOLVE-DIRECT',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subDays(5),
    ]);

    Livewire::withQueryParams([
        'tableAction' => 'resolve_km_report',
        'tableActionRecord' => (string) $trip->id,
        'tripSearch' => $trip->trip_code,
        'activeStatusFilter' => 'all',
        'orderType' => 'all',
        'activePlaceFilter' => 'all',
        'vehicleOwner' => 'all',
    ])
        ->test(ListTrips::class)->loadTable()
        ->assertSet('activeStatusFilter', 'all')
        ->assertSet('orderType', 'all')
        ->assertSet('activePlaceFilter', 'all')
        ->assertSet('tripSearch', $trip->trip_code)
        ->assertCanSeeTableRecords([$trip]);
});

test('trip destination falls back to checkpoint delivery point when order has no delivery points', function () {
    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-DP-FB', 'name' => 'Khách fallback']);
    $pickupLoc = Location::create([
        'code' => 'SEVT 1-2',
        'name' => 'SEVT 1-2',
        'address' => 'KCN Yên Bình',
        'lat' => 21.4,
        'lng' => 105.9,
        'loc_type' => 'pickup',
    ]);
    $asglLoc = Location::create([
        'code' => 'ASGL',
        'name' => 'Kho ASGL',
        'address' => 'Nội Bài',
        'lat' => 21.2,
        'lng' => 105.8,
        'loc_type' => 'delivery',
    ]);
    $vehicle = Vehicle::create([
        'plate_number' => '29H-19883',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $otherOrder = Order::create([
        'order_code' => 'ORD-OTHER-DP',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'created_by' => auth()->id(),
        'status' => OrderStatus::Completed,
        'pickup_location_id' => $pickupLoc->id,
    ]);
    $sharedDp = $otherOrder->deliveryPoints()->create([
        'location_id' => $asglLoc->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-DP-FB',
        'vehicle_id' => $vehicle->id,
        'start_location_id' => $pickupLoc->id,
        'end_location_id' => null,
        'status' => TripStatus::Completed,
    ]);

    $order = Order::create([
        'order_code' => 'ASG-436-FB',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'created_by' => auth()->id(),
        'trip_id' => $trip->id,
        'type' => OrderType::Hhhk,
        'status' => OrderStatus::Completed,
        'pickup_location_id' => $pickupLoc->id,
    ]);

    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $sharedDp->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => now()->subMinutes(20),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $sharedDp->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => now(),
    ]);

    $trip->load(['orders.deliveryPoints.location', 'checkpoints.deliveryPoint.location', 'endLocation']);

    expect(TripsTable::getDeliveryDestination($trip))->toBe('ASGL')
        ->and(app(TripExcelExportService::class)->buildJourneyString($order, $trip))->toBe('SEVT 1-2 -> ASGL');
});

test('trip form deduplicates delivery point options by location and auto-fills order delivery point', function () {
    $area = Area::create(['code' => 'NBO-DEDUP', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-DEDUP', 'name' => 'Khách dedup']);
    $asglLoc = Location::create([
        'code' => 'ASGL',
        'name' => 'Kho ASGL',
        'address' => 'Nội Bài',
        'lat' => 21.2,
        'lng' => 105.8,
        'loc_type' => 'delivery',
    ]);

    $otherOrder = Order::create([
        'order_code' => 'ORD-OTHER-ASGL',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'created_by' => auth()->id(),
        'status' => OrderStatus::Completed,
    ]);
    $otherOrder->deliveryPoints()->create([
        'location_id' => $asglLoc->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $order = Order::create([
        'order_code' => 'ORD-CURR-ASGL',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'created_by' => auth()->id(),
        'status' => OrderStatus::InTransit,
    ]);
    $ownDp = $order->deliveryPoints()->create([
        'location_id' => $asglLoc->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $get = fn (string $key): mixed => match ($key) {
        'order_id' => $order->id,
        'delivery_point_id' => null,
        'checkpoint_type' => CheckpointType::ArrivedDelivery->value,
        '../../checkpoints' => [
            ['checkpoint_type' => CheckpointType::Started->value, 'order_id' => $order->id, 'delivery_point_id' => null],
        ],
        default => null,
    };

    $optionsMethod = new ReflectionMethod(TripForm::class, 'deliveryPointOptionsForCheckpoint');
    $optionsMethod->setAccessible(true);
    $options = $optionsMethod->invoke(null, $get);

    $defaultMethod = new ReflectionMethod(TripForm::class, 'defaultDeliveryPointId');
    $defaultMethod->setAccessible(true);
    $defaultId = $defaultMethod->invoke(null, $get, 'checkpoints');

    expect($options)->toBe([$ownDp->id => 'ASGL'])
        ->and($defaultId)->toBe($ownDp->id);
});

test('trip form advances delivery point sequentially for multi-point orders', function () {
    $area = Area::create(['code' => 'NBO-MP', 'name' => 'Nội Bài MP', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-MP', 'name' => 'Khách đa điểm']);
    $locAlsc = Location::create(['code' => 'ALSC-MP', 'name' => 'ALSC', 'address' => 'HN', 'lat' => 21.2, 'lng' => 105.8, 'loc_type' => 'delivery']);
    $locSdv = Location::create(['code' => 'SDV-MP', 'name' => 'SDV', 'address' => 'BN', 'lat' => 21.3, 'lng' => 106.0, 'loc_type' => 'delivery']);

    $order = Order::create([
        'order_code' => 'ORD-2DP',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'created_by' => auth()->id(),
        'status' => OrderStatus::InTransit,
    ]);
    $dp1 = $order->deliveryPoints()->create(['location_id' => $locAlsc->id, 'sequence' => 1, 'status' => 'pending']);
    $dp2 = $order->deliveryPoints()->create(['location_id' => $locSdv->id, 'sequence' => 2, 'status' => 'pending']);

    $nextMethod = new ReflectionMethod(TripForm::class, 'getNextCheckpointType');
    $nextMethod->setAccessible(true);
    $defaultMethod = new ReflectionMethod(TripForm::class, 'defaultDeliveryPointId');
    $defaultMethod->setAccessible(true);

    $afterDp1Completed = [
        ['checkpoint_type' => CheckpointType::LeftPickup->value, 'order_id' => $order->id, 'delivery_point_id' => null],
        ['checkpoint_type' => CheckpointType::ArrivedDelivery->value, 'order_id' => $order->id, 'delivery_point_id' => $dp1->id],
        ['checkpoint_type' => CheckpointType::Completed->value, 'order_id' => $order->id, 'delivery_point_id' => $dp1->id],
    ];
    $getAfterDp1 = fn (string $key): mixed => match ($key) {
        'order_id' => $order->id,
        '../../checkpoints' => $afterDp1Completed,
        default => null,
    };

    expect($nextMethod->invoke(null, $getAfterDp1, 'checkpoints'))->toBe(CheckpointType::ArrivedDelivery)
        ->and($defaultMethod->invoke(null, $getAfterDp1, 'checkpoints'))->toBe($dp2->id);

    $afterDp2Completed = [
        ...$afterDp1Completed,
        ['checkpoint_type' => CheckpointType::ArrivedDelivery->value, 'order_id' => $order->id, 'delivery_point_id' => $dp2->id],
        ['checkpoint_type' => CheckpointType::Completed->value, 'order_id' => $order->id, 'delivery_point_id' => $dp2->id],
    ];
    $getAfterDp2 = fn (string $key): mixed => match ($key) {
        'order_id' => $order->id,
        '../../checkpoints' => $afterDp2Completed,
        default => null,
    };

    expect($nextMethod->invoke(null, $getAfterDp2, 'checkpoints'))->toBe(CheckpointType::End)
        ->and($defaultMethod->invoke(null, $getAfterDp2, 'checkpoints'))->toBe($dp2->id);
});

test('editing trip checkpoint delivery point updates order delivery point and relinks checkpoints', function () {
    $area = Area::create(['code' => 'NBA-SYNC', 'name' => 'Nội Bài Sync', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-SYNC', 'name' => 'Khách sync']);
    $locWrong = Location::create(['code' => 'ALSW-S', 'name' => 'ALSW', 'address' => 'ALSW addr', 'lat' => 21.21, 'lng' => 105.81, 'loc_type' => 'delivery']);
    $locRight = Location::create(['code' => 'ACSV-S', 'name' => 'ACSV', 'address' => 'ACSV addr', 'lat' => 21.22, 'lng' => 105.82, 'loc_type' => 'delivery']);
    $vehicle = Vehicle::create([
        'plate_number' => '29E-12091',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $otherOrder = Order::create([
        'order_code' => 'ORD-FOREIGN-ACSV',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'created_by' => auth()->id(),
        'status' => OrderStatus::Completed,
    ]);
    $foreignDpAcsv = $otherOrder->deliveryPoints()->create([
        'location_id' => $locRight->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-SYNC-596',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Delivered,
    ]);
    $order = Order::create([
        'order_code' => 'ASG-596-TEST',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'created_by' => auth()->id(),
        'trip_id' => $trip->id,
        'status' => OrderStatus::Completed,
    ]);
    $ownWrongDp = $order->deliveryPoints()->create([
        'location_id' => $locWrong->id,
        'sequence' => 1,
        'status' => 'delivered',
    ]);

    $cpArrived = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $foreignDpAcsv->id,
        'checkpoint_type' => CheckpointType::ArrivedDelivery,
        'occurred_at' => now()->subMinutes(10),
    ]);
    $cpCompleted = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'order_id' => $order->id,
        'delivery_point_id' => $foreignDpAcsv->id,
        'checkpoint_type' => CheckpointType::Completed,
        'occurred_at' => now(),
    ]);

    app(DeliveryProgressSyncService::class)->syncOrderDeliveryPointsFromCheckpoints($trip);

    $ownWrongDp->refresh();
    $cpArrived->refresh();
    $cpCompleted->refresh();

    expect($ownWrongDp->location_id)->toBe($locRight->id)
        ->and($cpArrived->delivery_point_id)->toBe($ownWrongDp->id)
        ->and($cpCompleted->delivery_point_id)->toBe($ownWrongDp->id)
        ->and(TripsTable::getDeliveryDestination($trip->fresh(['orders.deliveryPoints.location', 'checkpoints.deliveryPoint.location', 'endLocation'])))->toBe('ACSV-S');
});

test('new checkpoint occurred_at defaults to previous checkpoint plus one minute', function () {
    $method = new ReflectionMethod(TripForm::class, 'defaultCheckpointOccurredAt');

    $getFirst = fn (string $key): mixed => match ($key) {
        '../../checkpoints' => [],
        '../../started_at' => '2026-11-18 14:17',
        default => null,
    };

    expect($method->invoke(null, $getFirst, 'checkpoints', null))->toBe('2026-11-18 14:17');

    $getSubsequent = fn (string $key): mixed => match ($key) {
        '../../checkpoints' => [
            ['checkpoint_type' => CheckpointType::Started->value, 'occurred_at' => '2026-11-18 14:17:00'],
            ['checkpoint_type' => CheckpointType::ArrivedPickup->value, 'occurred_at' => '2026-11-18 14:25'],
            ['checkpoint_type' => CheckpointType::LeftPickup->value, 'occurred_at' => null],
        ],
        '../../started_at' => '2026-11-18 14:17',
        default => null,
    };

    expect($method->invoke(null, $getSubsequent, 'checkpoints', null))->toBe('2026-11-18 14:26');
});

test('ListTrips showMineOnly button filters trips with orders or empty runs created by current user', function () {
    $currentUser = User::first();
    $otherUser = User::factory()->create();

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true]);
    $customer = Customer::create(['code' => 'CUST-MINE', 'name' => 'Khách mine']);
    $vehicle = Vehicle::create([
        'plate_number' => '29C-88888',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    // 1. Chuyến không hàng do user hiện tại tạo (TripObserver tự gán created_by = Auth::id())
    $myEmptyTrip = Trip::create([
        'trip_code' => 'TRIP-MINE-EMPTY',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Started,
        'is_empty_run' => true,
        'started_at' => now(),
    ]);
    expect($myEmptyTrip->created_by)->toBe($currentUser->id);

    // 2. Chuyến do người khác tạo nhưng chứa đơn hàng do user hiện tại tạo
    $myOrderTrip = Trip::create([
        'trip_code' => 'TRIP-MINE-ORDER',
        'vehicle_id' => $vehicle->id,
        'created_by' => $otherUser->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
    ]);
    Order::create([
        'order_code' => 'ORD-MINE-1',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'trip_id' => $myOrderTrip->id,
        'type' => OrderType::Hhhk,
        'status' => OrderStatus::InTransit,
        'created_by' => $currentUser->id,
    ]);

    // 3. Chuyến do người khác tạo hoàn toàn (cả chuyến và đơn đều của người khác)
    $otherTrip = Trip::create([
        'trip_code' => 'TRIP-OTHER-USER',
        'vehicle_id' => $vehicle->id,
        'created_by' => $otherUser->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
    ]);
    Order::create([
        'order_code' => 'ORD-OTHER-1',
        'customer_id' => $customer->id,
        'area_id' => $area->id,
        'trip_id' => $otherTrip->id,
        'type' => OrderType::Hhhk,
        'status' => OrderStatus::InTransit,
        'created_by' => $otherUser->id,
    ]);

    // Mặc định showMineOnly = false: thấy cả 3 chuyến + thấy nút "Đơn của tôi"
    $component = Livewire::test(ListTrips::class, [
        'orderType' => 'all',
        'activePlaceFilter' => 'all',
    ])
        ->loadTable()
        ->assertSet('showMineOnly', false)
        ->assertSee('Đơn của tôi')
        ->assertSeeHtml("wire:click=\"\$toggle('showMineOnly')\"")
        ->assertCanSeeTableRecords([$myEmptyTrip, $myOrderTrip, $otherTrip]);

    // Bật "Đơn của tôi": chỉ còn chuyến của tôi (chuyến không hàng của tôi + chuyến có đơn của tôi)
    $component
        ->set('showMineOnly', true)
        ->assertSet('showMineOnly', true)
        ->assertCanSeeTableRecords([$myEmptyTrip, $myOrderTrip])
        ->assertCanNotSeeTableRecords([$otherTrip]);

    /** @var ListTrips $instance */
    $instance = $component->instance();
    expect($instance->getOrderTypeCount('all'))->toBe(2)
        ->and($instance->getOrderTypeCount('empty'))->toBe(1)
        ->and($instance->getOrderTypeCount('HHHK'))->toBe(1)
        ->and($instance->getExportTripsQuery(['show_mine_only' => true])->pluck('trips.id')->all())
        ->toEqualCanonicalizing([$myEmptyTrip->id, $myOrderTrip->id]);
});

test('cột điểm đến theo thứ tự mốc giao thực tế khi lái xe giao khác thứ tự dự kiến', function () {
    $area = Area::create(['code' => 'ORD-SEQ', 'name' => 'Khu thứ tự']);
    $customer = Customer::create(['code' => 'CUST-SEQ', 'name' => 'Khách thứ tự']);
    $user = User::factory()->create();
    $sdv = Location::create(['code' => 'SDV', 'name' => 'SDV', 'address' => 'A', 'lat' => 21, 'lng' => 105, 'loc_type' => 'delivery']);
    $clc = Location::create(['code' => 'CLC', 'name' => 'CLC', 'address' => 'B', 'lat' => 21, 'lng' => 105, 'loc_type' => 'delivery']);
    $vehicle = Vehicle::create([
        'plate_number' => '29E-36355',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);
    $trip = Trip::create(['trip_code' => 'TRIP-SEQ', 'vehicle_id' => $vehicle->id, 'status' => TripStatus::Started, 'started_at' => now()->subHours(3)]);
    $order = Order::create([
        'order_code' => 'ASG-940-T',
        'trip_id' => $trip->id,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'created_by' => $user->id,
        'status' => OrderStatus::Sent,
    ]);
    $sdvPoint = $order->deliveryPoints()->create(['location_id' => $sdv->id, 'sequence' => 1]);
    $order->deliveryPoints()->create(['location_id' => $clc->id, 'sequence' => 2]);
    $later = Location::create(['code' => 'LATER', 'name' => 'Chưa giao', 'address' => 'C', 'lat' => 21, 'lng' => 105, 'loc_type' => 'delivery']);
    $order->deliveryPoints()->create(['location_id' => $later->id, 'sequence' => 3]);
    $clcPoint = $order->deliveryPoints()->where('location_id', $clc->id)->first();

    foreach ([[$clcPoint, 60], [$sdvPoint, 30]] as [$point, $minutesAgo]) {
        TripCheckpoint::create([
            'trip_id' => $trip->id,
            'order_id' => $order->id,
            'delivery_point_id' => $point->id,
            'checkpoint_type' => CheckpointType::ArrivedDelivery,
            'occurred_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    $trip->load(['orders.deliveryPoints.location', 'checkpoints.deliveryPoint.location', 'endLocation']);

    expect(TripsTable::getDeliveryDestination($trip))->toBe('CLC → SDV → LATER');
});
