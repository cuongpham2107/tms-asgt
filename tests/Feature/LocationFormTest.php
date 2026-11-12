<?php

use App\Enums\OrderType;
use App\Filament\Resources\Locations\Pages\CreateLocation;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Models\Area;
use App\Models\Location;
use App\Models\User;
use EduardoRibeiroDev\FilamentLeaflet\Fields\MapPicker;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

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

test('location form areas field pre-selects default area when defaultAreaId is provided', function () {
    $area = Area::create([
        'type' => OrderType::Hhhk,
        'code' => 'NBO',
        'name' => 'Nội bộ TN',
        'is_active' => true,
    ]);

    $schema = Schema::make();
    $configuredSchema = LocationForm::configure($schema);
    $components = $configuredSchema->getComponents();

    $areasComponent = collect($components)
        ->first(fn ($component) => $component->getName() === 'areas');

    expect($areasComponent)->not->toBeNull();
    expect($areasComponent->isMultiple())->toBeTrue();

    // Test with integer ID
    $schema1 = Schema::make();
    $components1 = LocationForm::configure($schema1, $area->id)->getComponents();
    $areasComponent1 = collect($components1)->first(fn ($c) => $c->getName() === 'areas');
    $default1 = $areasComponent1->getDefaultState();
    expect($default1)->toBe([$area->id]);

    // Test with string code
    $schema2 = Schema::make();
    $components2 = LocationForm::configure($schema2, 'NBO')->getComponents();
    $areasComponent2 = collect($components2)->first(fn ($c) => $c->getName() === 'areas');
    $default2 = $areasComponent2->getDefaultState();
    expect($default2)->toBe([$area->id]);

    // Test with Closure
    $schema3 = Schema::make();
    $components3 = LocationForm::configure($schema3, fn () => $area->id)->getComponents();
    $areasComponent3 = collect($components3)->first(fn ($c) => $c->getName() === 'areas');
    $default3 = $areasComponent3->getDefaultState();
    expect($default3)->toBe([$area->id]);
});

test('location form geofence_radius_m and map picker are configured with circle visualization', function () {
    $schema = Schema::make();
    $configuredSchema = LocationForm::configure($schema);
    $components = $configuredSchema->getComponents();

    // 1. geofence_radius_m component
    $radiusComponent = collect($components)
        ->first(fn ($component) => $component->getName() === 'geofence_radius_m');

    expect($radiusComponent)->not->toBeNull();
    expect($radiusComponent->getSuffixLabel())->toBe('m');
    expect($radiusComponent->getMinValue())->toBe(100);
    expect($radiusComponent->getMaxValue())->toBe(5000);
    expect($radiusComponent->getExtraInputAttributes())->toHaveKey('data-geofence-radius-input');

    // 2. coordinates component
    $coordinatesComponent = collect($components)
        ->first(fn ($component) => $component->getName() === 'coordinates');

    expect($coordinatesComponent)->not->toBeNull();
    expect($coordinatesComponent->getView())->toBe('filament.forms.components.location-map-picker');
});

test('location map picker blade view renders with geofence alpine component', function () {
    $perm1 = Permission::firstOrCreate(['name' => 'ViewAny:Location', 'guard_name' => 'web']);
    $perm2 = Permission::firstOrCreate(['name' => 'Create:Location', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->givePermissionTo([$perm1, $perm2]);
    $this->actingAs($user);

    Livewire::test(CreateLocation::class)
        ->assertSuccessful()
        ->assertSee('locationGeofenceMap')
        ->assertSee('geofence_radius_m')
        ->assertSee('data-geofence-radius-input');
});

test('creating location with area creates single unique record', function () {
    $area = Area::create([
        'code' => 'NBA',
        'name' => 'Hàng đến NBA',
        'is_active' => true,
    ]);

    $location = Location::create([
        'area_id' => $area->id,
        'code' => 'KHO-NBA-01',
        'name' => 'Kho Nội Bài 01',
        'loc_type' => 'warehouse',
        'is_active' => true,
    ]);

    expect(Location::where('code', 'KHO-NBA-01')->count())->toBe(1);
    expect($location->area_id)->toBe($area->id);

    $location->update(['name' => 'Kho Nội Bài 01 Updated']);
    expect($location->refresh()->name)->toBe('Kho Nội Bài 01 Updated');
});
