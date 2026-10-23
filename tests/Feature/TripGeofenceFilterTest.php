<?php

use App\Enums\GeoCheckStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('wrong location status filter lists only trips with an out-of-range checkpoint', function () {
    Gate::before(fn () => true);
    $admin = User::factory()->create();
    $admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
    $driver = User::factory()->create();
    $vehicle = Vehicle::create([
        'plate_number' => '29C-00001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $okTrip = Trip::create(['trip_code' => 'TRIP-OK', 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'status' => TripStatus::Started]);
    TripCheckpoint::create(['trip_id' => $okTrip->id, 'checkpoint_type' => 'started', 'occurred_at' => now(), 'vehicle_geo_status' => GeoCheckStatus::Valid]);

    $wrongTrip = Trip::create(['trip_code' => 'TRIP-WRONG', 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'status' => TripStatus::Started]);
    TripCheckpoint::create(['trip_id' => $wrongTrip->id, 'checkpoint_type' => 'arrived_pickup', 'occurred_at' => now(), 'location_geo_status' => GeoCheckStatus::OutOfRange]);

    $this->actingAs($admin);

    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->set('activeStatusFilter', 'geo_out_of_range')
        ->assertCanSeeTableRecords([$wrongTrip])
        ->assertCanNotSeeTableRecords([$okTrip]);
});

test('trip timeline shows the wrong location warning with distance', function () {
    $driver = User::factory()->create();
    $vehicle = Vehicle::create([
        'plate_number' => '29C-00002',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);
    $trip = Trip::create(['trip_code' => 'TRIP-TL', 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'status' => TripStatus::Started]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => 'arrived_pickup',
        'occurred_at' => now(),
        'location_geo_status' => GeoCheckStatus::OutOfRange,
        'location_distance_m' => 1250,
    ]);

    $html = view('filament.resources.trips.components.trip-timeline-popup', ['trip' => $trip->fresh()])->render();

    expect($html)->toContain('Sai vị trí: Xe cách điểm 1.250 m');
});
