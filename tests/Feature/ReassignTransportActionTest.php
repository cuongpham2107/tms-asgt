<?php

use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);

    $this->driver1 = User::factory()->create(['name' => 'Driver 1']);
    $this->driver1->assignRole($this->driverRole);

    $this->driver2 = User::factory()->create(['name' => 'Driver 2']);
    $this->driver2->assignRole($this->driverRole);

    $this->vehicle1 = Vehicle::create([
        'plate_number' => '51C-111.11',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->vehicle2 = Vehicle::create([
        'plate_number' => '51C-222.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_driver_id' => $this->driver2->id,
    ]);

    $this->pendingTrip = Trip::create([
        'trip_code' => 'TRIP-PENDING-1',
        'vehicle_id' => $this->vehicle1->id,
        'driver_id' => $this->driver1->id,
        'status' => TripStatus::Pending,
    ]);

    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

test('can reassign vehicle and driver to a pending trip', function () {
    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->mountTableAction('reassign_transport', $this->pendingTrip)
        ->setTableActionData([
            'vehicle_id' => $this->vehicle2->id,
            'driver_id' => $this->driver2->id,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $this->pendingTrip->refresh();
    expect($this->pendingTrip->vehicle_id)->toBe($this->vehicle2->id);
    expect($this->pendingTrip->driver_id)->toBe($this->driver2->id);

    $this->vehicle1->refresh();
    expect($this->vehicle1->status)->toBe(VehicleStatus::On);

    $this->vehicle2->refresh();
    expect($this->vehicle2->status)->toBe(VehicleStatus::Running);
});

test('updates shift_id when new driver has active shift', function () {
    $shift = DriverShift::create([
        'driver_id' => $this->driver2->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->mountTableAction('reassign_transport', $this->pendingTrip)
        ->setTableActionData([
            'vehicle_id' => $this->vehicle2->id,
            'driver_id' => $this->driver2->id,
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $this->pendingTrip->refresh();
    expect($this->pendingTrip->shift_id)->toBe($shift->id);
});

test('reassign_transport action is hidden when trip is not pending', function () {
    $startedTrip = Trip::create([
        'trip_code' => 'TRIP-STARTED-1',
        'vehicle_id' => $this->vehicle1->id,
        'driver_id' => $this->driver1->id,
        'status' => TripStatus::Started,
        'started_at' => now(),
    ]);

    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->assertTableActionHidden('reassign_transport', $startedTrip)
        ->assertTableActionVisible('reassign_transport', $this->pendingTrip);
});
