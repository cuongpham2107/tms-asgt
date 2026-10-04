<?php

use App\Enums\AssignmentEndReason;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Trip\TripDriverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    $this->actingAs(User::factory()->create());

    $vehicle = Vehicle::create([
        'plate_number' => '51C-777.77',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->driverA = User::factory()->create(['is_active' => true]);
    $this->driverB = User::factory()->create(['is_active' => true]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-ASSIGN-1',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
    ]);
    app(TripDriverService::class)->openAssignment($this->trip, $this->driverA);
    $this->trip->update(['status' => TripStatus::Delivering, 'started_at' => now()]);
});

function assignDriverViaTable(Trip $trip, User $driver): void
{
    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->mountTableAction('assign_driver', $trip)
        ->setTableActionData(['driver_id' => $driver->id, 'note' => 'Điều hành gán'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();
}

test('dispatcher assigns a driver to a trip waiting in driver swap', function () {
    app(TripDriverService::class)->requestSwap($this->trip->fresh(), $this->driverA, AssignmentEndReason::ShiftHandover);

    assignDriverViaTable($this->trip->fresh(), $this->driverB);

    $trip = $this->trip->fresh();
    expect($trip->status)->toBe(TripStatus::Delivering)
        ->and($trip->driver_id)->toBe($this->driverB->id)
        ->and($trip->driverAssignments()->count())->toBe(2);
});

test('dispatcher replaces the driver of a running trip without changing its status', function () {
    assignDriverViaTable($this->trip->fresh(), $this->driverB);

    $trip = $this->trip->fresh();
    expect($trip->status)->toBe(TripStatus::Delivering)
        ->and($trip->driver_id)->toBe($this->driverB->id)
        ->and($trip->driverAssignments()->first()->end_reason)->toBe(AssignmentEndReason::Reassigned);
});
