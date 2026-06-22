<?php

use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Location;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('location model coordinates getter and setter works correctly', function () {
    $location = new Location;

    // 1. Test set with array
    $location->coordinates = ['lat' => 21.0285, 'lng' => 105.8542];
    expect((float) $location->lat)->toBe(21.0285);
    expect((float) $location->lng)->toBe(105.8542);
    expect($location->coordinates)->toBe(['lat' => 21.0285, 'lng' => 105.8542]);

    // 2. Test set with null values
    $location->coordinates = null;
    expect($location->lat)->toBeNull();
    expect($location->lng)->toBeNull();
    expect($location->coordinates)->toBeNull();

    // 3. Test set with sequential array
    $location->coordinates = [10.762622, 106.660172];
    expect((float) $location->lat)->toBe(10.762622);
    expect((float) $location->lng)->toBe(106.660172);
    expect($location->coordinates)->toBe(['lat' => 10.762622, 'lng' => 106.660172]);
});

test('location form is configured correctly with map picker', function () {
    $schema = Schema::make();
    $configuredSchema = LocationForm::configure($schema);
    $components = $configuredSchema->getComponents();

    // Find the coordinates component
    $coordinatesComponent = collect($components)
        ->first(fn ($component) => $component->getName() === 'coordinates');

    expect($coordinatesComponent)->not->toBeNull();
    expect($coordinatesComponent)->toBeInstanceOf(MapPicker::class);
    expect($coordinatesComponent->getLabel())->toBe('Chọn vị trí trên bản đồ');
});
