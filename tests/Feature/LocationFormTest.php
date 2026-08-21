<?php

use App\Enums\OrderType;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Area;
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

test('location form area_id options use area ID as key and include formatted label', function () {
    $area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'NBO',
        'name' => 'Nội bộ TN',
        'is_active' => true,
    ]);

    $schema = Schema::make();
    $configuredSchema = LocationForm::configure($schema);
    $components = $configuredSchema->getComponents();

    $areaComponent = collect($components)
        ->first(fn ($component) => $component->getName() === 'area_id');

    expect($areaComponent)->not->toBeNull();
    $options = $areaComponent->getOptions();
    expect($options)->toHaveKey($area->id);
    expect($options[$area->id])->toContain('NBO - Nội bộ TN (HHHK)');
});

test('location form area_id pre-selects default area when defaultAreaId is provided', function () {
    $area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'NBO',
        'name' => 'Nội bộ TN',
        'is_active' => true,
    ]);

    // Test with integer ID
    $schema1 = Schema::make();
    $components1 = LocationForm::configure($schema1, $area->id)->getComponents();
    $areaComponent1 = collect($components1)->first(fn ($c) => $c->getName() === 'area_id');
    $default1 = $areaComponent1->getDefaultState();
    expect($default1)->toBe($area->id);

    // Test with string code
    $schema2 = Schema::make();
    $components2 = LocationForm::configure($schema2, 'NBO')->getComponents();
    $areaComponent2 = collect($components2)->first(fn ($c) => $c->getName() === 'area_id');
    $default2 = $areaComponent2->getDefaultState();
    expect($default2)->toBe($area->id);

    // Test with Closure
    $schema3 = Schema::make();
    $components3 = LocationForm::configure($schema3, fn () => $area->id)->getComponents();
    $areaComponent3 = collect($components3)->first(fn ($c) => $c->getName() === 'area_id');
    $default3 = $areaComponent3->getDefaultState();
    expect($default3)->toBe($area->id);
});
