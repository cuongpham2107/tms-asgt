<?php

use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Widgets\VehicleTrackingMap;
use App\Models\User;
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
