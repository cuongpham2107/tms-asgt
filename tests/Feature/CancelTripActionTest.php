<?php

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driver = User::factory()->create();

    $this->vehicle = Vehicle::create([
        'plate_number' => '51C-123.45',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 250,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-CANCEL-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
        'start_km' => 100,
    ]);

    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

test('cancelling a trip resets the vehicle status to ready', function () {
    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->mountTableAction('cancel_trip', $this->trip)
        ->setTableActionData([
            'km_reading' => 250,
            'cancel_reason' => 'Test cancel',
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $this->trip->refresh();
    expect($this->trip->status)->toBe(TripStatus::Cancelled);
    expect($this->trip->cancelled_at)->not->toBeNull();

    $this->vehicle->refresh();
    expect($this->vehicle->status)->toBe(VehicleStatus::On);
    expect((float) $this->vehicle->current_mileage)->toBe(250.0);
});
