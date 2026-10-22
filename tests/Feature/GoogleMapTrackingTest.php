<?php

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Pages\GoogleMapTracking;
use App\Filament\Widgets\GoogleMapSidebar;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('google map tracking page mounts and returns valid map data with https tile layers', function () {
    Vehicle::create([
        'plate_number' => '29A-12345',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
        'gps_lat' => 21.0285,
        'gps_lng' => 105.8542,
    ]);

    $component = Livewire::test(GoogleMapTracking::class);

    $component->assertSuccessful();

    $page = new GoogleMapTracking;
    $page->mount();
    $mapData = $page->getMapData();

    expect($mapData)->toBeArray()
        ->and($mapData)->toHaveKeys(['mapId', 'tileLayersUrl', 'layersData'])
        ->and($mapData['tileLayersUrl'])->toBeArray();

    // Verify all tile layer URLs use HTTPS
    foreach ($mapData['tileLayersUrl'] as $tileLayer) {
        $url = $tileLayer[1];
        expect(str_starts_with($url, 'https://'))->toBeTrue();
    }
});

test('google map tracking handles vehicle selection change and does not deselect on map click', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '29B-99999',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::Running,
        'is_active' => true,
        'gps_lat' => 21.03,
        'gps_lng' => 105.85,
    ]);

    Livewire::test(GoogleMapTracking::class)
        ->call('handleLayerClick', 'vehicle-'.$vehicle->id)
        ->assertSet('selectedVehicleIds', [$vehicle->id])
        ->assertDispatched('vehicleSelectionChanged', selectedIds: [$vehicle->id])
        // Map clicks must not clear selection
        ->call('handleMapClick', 21.03, 105.85)
        ->assertSet('selectedVehicleIds', [$vehicle->id])
        // Explicit clear selection
        ->call('clearSelectedVehicles')
        ->assertSet('selectedVehicleIds', [])
        ->assertDispatched('vehicleSelectionChanged', selectedIds: []);
});

test('google map sidebar synchronizes vehicle selection from map event', function () {
    $vehicle1 = Vehicle::create([
        'plate_number' => '29C-11111',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::Running,
        'is_active' => true,
        'gps_lat' => 21.02,
        'gps_lng' => 105.84,
    ]);

    $vehicle2 = Vehicle::create([
        'plate_number' => '29C-22222',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
        'gps_lat' => 21.04,
        'gps_lng' => 105.86,
    ]);

    $sidebar = Livewire::test(GoogleMapSidebar::class);

    // Initial state: none selected
    $vehicles = $sidebar->instance()->getVehicles();
    expect(collect($vehicles)->firstWhere('id', $vehicle1->id)['selected'])->toBeFalse()
        ->and(collect($vehicles)->firstWhere('id', $vehicle2->id)['selected'])->toBeFalse();

    // Map selects vehicle 1
    $sidebar->dispatch('vehicleSelectionChanged', selectedIds: [$vehicle1->id]);
    $vehiclesAfterSelect = $sidebar->instance()->getVehicles();
    expect(collect($vehiclesAfterSelect)->firstWhere('id', $vehicle1->id)['selected'])->toBeTrue()
        ->and(collect($vehiclesAfterSelect)->firstWhere('id', $vehicle2->id)['selected'])->toBeFalse();

    // Map clears selection
    $sidebar->dispatch('vehicleSelectionChanged', selectedIds: []);
    $vehiclesAfterClear = $sidebar->instance()->getVehicles();
    expect(collect($vehiclesAfterClear)->firstWhere('id', $vehicle1->id)['selected'])->toBeFalse()
        ->and(collect($vehiclesAfterClear)->firstWhere('id', $vehicle2->id)['selected'])->toBeFalse();
});

test('google map sidebar allows selecting a single vehicle and toggling checkboxes', function () {
    $vehicle1 = Vehicle::create([
        'plate_number' => '29C-33333',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::Running,
        'is_active' => true,
        'gps_lat' => 21.02,
        'gps_lng' => 105.84,
    ]);

    $vehicle2 = Vehicle::create([
        'plate_number' => '29C-44444',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
        'gps_lat' => 21.04,
        'gps_lng' => 105.86,
    ]);

    $sidebar = Livewire::test(GoogleMapSidebar::class);

    // Clicking vehicle 1 card focuses vehicle 1
    $sidebar->call('selectVehicle', $vehicle1->id)
        ->assertSet('selectedVehicleIds', [$vehicle1->id])
        ->assertDispatched('vehicleSelectionChanged', selectedIds: [$vehicle1->id]);

    // Clicking vehicle 2 card switches focus to vehicle 2
    $sidebar->call('selectVehicle', $vehicle2->id)
        ->assertSet('selectedVehicleIds', [$vehicle2->id])
        ->assertDispatched('vehicleSelectionChanged', selectedIds: [$vehicle2->id]);

    // Toggling vehicle 1 checkbox adds it to multi-selection
    $sidebar->call('toggleCheckbox', $vehicle1->id)
        ->assertSet('selectedVehicleIds', [$vehicle2->id, $vehicle1->id])
        ->assertDispatched('vehicleSelectionChanged', selectedIds: [$vehicle2->id, $vehicle1->id]);

    // Clicking vehicle 2 card when multi-selected sets selection exclusively to vehicle 2
    $sidebar->call('selectVehicle', $vehicle2->id)
        ->assertSet('selectedVehicleIds', [$vehicle2->id]);

    // Clicking vehicle 2 card when already the only selected vehicle deselects it
    $sidebar->call('selectVehicle', $vehicle2->id)
        ->assertSet('selectedVehicleIds', []);
});

test('google map tracking only draws vehicles at their real gps position, clustered and colored by status', function () {
    $tracked = Vehicle::create([
        'plate_number' => '29D-10001',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::Running,
        'is_active' => true,
        'gps_lat' => 21.2068,
        'gps_lng' => 105.8208,
    ]);

    $untracked = Vehicle::create([
        'plate_number' => '29D-10002',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $page = Livewire::test(GoogleMapTracking::class)->instance();
    $layers = collect($page->getMapData()['layersData'])->keyBy('id');

    expect($layers->get('vehicle-'.$tracked->id))
        ->coords->toBe([21.2068, 105.8208])
        ->group->toBe('vehicles')
        ->and($layers->get('vehicle-'.$tracked->id)['icon']['color'])->toBe('rgb(245, 158, 11)')
        ->and($layers->has('vehicle-'.$untracked->id))->toBeFalse()
        ->and($page->getVehiclesWithoutGpsCount())->toBe(1);
});

test('google map tracking shows only the selected vehicles on the map', function () {
    $make = fn (string $plate, float $lat) => Vehicle::create([
        'plate_number' => $plate,
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
        'gps_lat' => $lat,
        'gps_lng' => 105.85,
    ]);
    $selected = $make('29E-00001', 21.01);
    $other = $make('29E-00002', 21.02);

    $component = Livewire::test(GoogleMapTracking::class)
        ->dispatch('vehicleSelectionChanged', selectedIds: [$selected->id]);
    $markerIds = collect($component->instance()->getMapData()['layersData'])->pluck('id');

    expect($markerIds)->toContain('vehicle-'.$selected->id)
        ->not->toContain('vehicle-'.$other->id);

    $component->call('clearSelectedVehicles');
    $markerIds = collect($component->instance()->getMapData()['layersData'])->pluck('id');

    expect($markerIds)->toContain('vehicle-'.$selected->id, 'vehicle-'.$other->id);
});

test('google map tracking draws selected vehicle route in time order to its current position and plans remaining stops', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $vehicle = Vehicle::create([
        'plate_number' => '29D-20001',
        'owner' => 'ASGL',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::Running,
        'is_active' => true,
        'gps_lat' => 21.10,
        'gps_lng' => 105.90,
    ]);
    $trip = Trip::create([
        'trip_code' => 'TRIP-MAP-01',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->user->id,
        'status' => TripStatus::Started,
    ]);
    $order = Order::create([
        'order_code' => 'ORD-MAP-01',
        'type' => OrderType::Hhhk,
        'area_id' => Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1])->id,
        'customer_id' => Customer::create(['code' => 'ASGL', 'name' => 'ASGL', 'is_active' => true])->id,
        'trip_id' => $trip->id,
        'cargo_type' => CargoType::Gcr,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
    ]);
    $makeStop = fn (string $code, float $lat, float $lng, int $sequence, OrderDeliveryPointStatus $status) => OrderDeliveryPoint::create([
        'order_id' => $order->id,
        'location_id' => Location::create(['code' => $code, 'name' => $code, 'lat' => $lat, 'lng' => $lng, 'loc_type' => 'delivery', 'is_active' => true])->id,
        'sequence' => $sequence,
        'address' => $code,
        'status' => $status,
    ]);
    $makeStop('DONE', 21.05, 105.85, 1, OrderDeliveryPointStatus::Delivered);
    $pending = $makeStop('NEXT', 21.30, 106.00, 2, OrderDeliveryPointStatus::Pending);

    // Inserted out of order: the route must follow occurred_at, not insertion order.
    TripCheckpoint::create(['trip_id' => $trip->id, 'checkpoint_type' => CheckpointType::LeftPickup, 'occurred_at' => now()->subHour(), 'gps_lat' => 21.02, 'gps_lng' => 105.84]);
    TripCheckpoint::create(['trip_id' => $trip->id, 'checkpoint_type' => CheckpointType::Started, 'occurred_at' => now()->subHours(2), 'gps_lat' => 21.00, 'gps_lng' => 105.80]);

    $page = Livewire::test(GoogleMapTracking::class)
        ->call('handleLayerClick', 'vehicle-'.$vehicle->id)
        ->instance();
    $layers = collect($page->getMapData()['layersData']);

    $traveled = $layers->first(fn (array $l): bool => str_starts_with($l['id'], "trk-{$vehicle->id}-traveled-"));
    $planned = $layers->first(fn (array $l): bool => str_starts_with($l['id'], "trk-{$vehicle->id}-planned-"));

    expect($traveled['points'])->toBe([[21.0, 105.8], [21.02, 105.84], [21.1, 105.9]])
        ->and($planned['points'])->toBe([[21.1, 105.9], [21.3, 106.0]])
        ->and($layers->pluck('id'))->toContain("trk-{$vehicle->id}-stop-dp-{$pending->id}");
});
