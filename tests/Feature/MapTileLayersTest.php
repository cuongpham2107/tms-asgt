<?php

use App\Enums\TripStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Widgets\VehicleTrackingMap;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    $this->actingAs(User::factory()->create());
});

test('no map in the app loads OpenStreetMap tiles, which some networks block', function () {
    $offenders = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->filter(fn ($file) => str_contains($file->getContents(), 'TileLayer::OpenStreetMap')
            || str_contains($file->getContents(), 'tile.openstreetmap.org'))
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('the gps tracking map uses Vietnamese Google street tiles first, over https', function () {
    $tiles = Livewire::test(VehicleTrackingMap::class)->instance()->getRendererConfiguration()['tiles'];

    expect($tiles[0][1])->toStartWith('https://')
        ->toContain('lyrs=m')
        ->toContain('hl=vi');
});

test('the location form map shows Google street tiles', function () {
    Livewire::test(CreateLocation::class)
        ->assertSeeHtml('lyrs=m')
        ->assertDontSeeHtml('tile.openstreetmap.org');
});

test('the gps modal uses a Google-tiles Leaflet map instead of an OpenStreetMap iframe', function () {
    $vehicle = Vehicle::factory()->create(['gps_lat' => 21.0285, 'gps_lng' => 105.8542]);

    Livewire::test(VehicleDispatchBoard::class)
        ->call('openGpsModal', $vehicle->id)
        ->assertSeeHtml('data-gps-map')
        ->assertSeeHtml('data-lat="21.0285"')
        ->assertDontSeeHtml('openstreetmap.org');

    expect(File::get(resource_path('js/filament/gps-modal-map.ts')))->toContain('lyrs=m');
});

test('table map columns use Google tiles instead of the plugin OpenStreetMap default', function () {
    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29H-MAP-TEST',
        'is_active' => true,
        'gps_lat' => 21.05,
        'gps_lng' => 105.8542,
    ]);

    Trip::factory()->create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => User::factory()->create()->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
    ]);

    $tripsHtml = Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->call('loadTable')
        ->html();

    $vehiclesHtml = Livewire::test(ListVehicles::class)->call('loadTable')->html();

    expect($tripsHtml)->toContain('mt1.google.com')->not->toContain('openstreetmap');
    expect($vehiclesHtml)->toContain('mt1.google.com')->not->toContain('openstreetmap');
});
