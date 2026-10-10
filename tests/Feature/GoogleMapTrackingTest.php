<?php

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Pages\GoogleMapTracking;
use App\Filament\Widgets\GoogleMapSidebar;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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
