<?php

use App\Enums\CheckpointType;
use App\Enums\DriverSwapReason;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Actions\DriverSwapAction;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
    $driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);
    foreach (['ViewAny:Trip', 'View:Trip', 'Update:Trip'] as $permission) {
        Permission::create(['name' => $permission, 'guard_name' => 'web']);
        $adminRole->givePermissionTo($permission);
    }

    $admin = User::factory()->create();
    $admin->assignRole($adminRole);
    $this->actingAs($admin);

    [$this->driverA, $this->driverB, $this->driverC] = collect(['A', 'B', 'C'])
        ->map(function (string $name) use ($driverRole): User {
            $driver = User::factory()->create(['name' => "Driver {$name}", 'is_active' => true]);
            $driver->assignRole($driverRole);

            return $driver;
        })
        ->all();

    $this->vehicle = Vehicle::create([
        'plate_number' => 'REASSIGN-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 1070,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-REASSIGN',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driverA->id,
        'status' => TripStatus::DriverSwap,
        'start_km' => 1000,
        'started_at' => now()->subHours(3),
    ]);

    reassignTestCheckpoint($this, CheckpointType::Started, 1000, now()->subHours(3));
    $this->handoverCheckpoint = reassignTestCheckpoint($this, CheckpointType::DriverSwap, 1050, now()->subHours(2));
});

function reassignTestCheckpoint(object $test, CheckpointType $type, float $km, $occurredAt, ?User $driver = null): TripCheckpoint
{
    return TripCheckpoint::create([
        'trip_id' => $test->trip->id,
        'checkpoint_type' => $type->value,
        'occurred_at' => $occurredAt,
        'km_reading' => $km,
        'driver_id' => ($driver ?? $test->driverA)->id,
        'vehicle_id' => $test->vehicle->id,
        'order_id' => null,
    ]);
}

function reassignTestCall(object $test, User $driver, ?Trip $trip = null): void
{
    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])
        ->callTableAction('reassign_driver', $trip ?? $test->trip, data: [
            'new_driver_id' => $driver->id,
            'reason' => DriverSwapReason::CargoNotUnloaded->value,
        ])
        ->assertHasNoTableActionErrors();
}

test('reassigning links the swap to the pending handover checkpoint without a second km', function () {
    reassignTestCall($this, $this->driverB);

    $swap = DriverSwap::where('trip_id', $this->trip->id)->sole();

    expect($swap->checkpoint_id)->toBe($this->handoverCheckpoint->id)
        ->and((float) $swap->handover_km)->toBe(1050.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(1070.0);
});

test('reassigning to the same driver who handed over still links the checkpoint', function () {
    reassignTestCall($this, $this->driverA);

    $swap = DriverSwap::where('trip_id', $this->trip->id)->sole();

    expect($swap->checkpoint_id)->toBe($this->handoverCheckpoint->id)
        ->and($swap->to_driver_id)->toBe($this->driverA->id);
});

test('changing the receiver before they start updates the existing swap', function () {
    foreach ([$this->driverB, $this->driverC] as $driver) {
        reassignTestCall($this, $driver, $this->trip->fresh());
        Trip::whereKey($this->trip->id)->update(['status' => TripStatus::DriverSwap]);
    }

    $swap = DriverSwap::where('trip_id', $this->trip->id)->sole();

    expect($swap->to_driver_id)->toBe($this->driverC->id);
});

test('reassigning after the receiver started without a new handover is refused', function () {
    reassignTestCall($this, $this->driverB);
    reassignTestCheckpoint($this, CheckpointType::ArrivedPickup, 1060, now()->subHour(), $this->driverB);
    Trip::whereKey($this->trip->id)->update(['status' => TripStatus::DriverSwap]);

    reassignTestCall($this, $this->driverC, $this->trip->fresh());

    expect(DriverSwap::where('trip_id', $this->trip->id)->count())->toBe(1);
});

test('reassigning a trip without any handover checkpoint is refused', function () {
    $this->handoverCheckpoint->delete();

    reassignTestCall($this, $this->driverB);

    expect(DriverSwap::where('trip_id', $this->trip->id)->exists())->toBeFalse();
});

test('web driver swap creates the handover checkpoint and links the swap to it', function () {
    $this->handoverCheckpoint->delete();
    $this->trip->update(['status' => TripStatus::Delivering, 'driver_id' => $this->driverA->id]);
    Order::create([
        'order_code' => 'REASSIGN-ORD-1',
        'type' => OrderType::Hhhk,
        'area_id' => Area::create(['type' => OrderType::Hhhk, 'code' => 'RA-TEST', 'name' => 'Reassign Area'])->id,
        'customer_id' => Customer::create(['code' => 'RA-CUST', 'name' => 'Reassign Customer', 'is_active' => true])->id,
        'trip_id' => $this->trip->id,
        'status' => OrderStatus::InTransit,
        'created_by' => $this->driverA->id,
    ]);

    $action = DriverSwapAction::make();
    $action->record($this->trip);
    $action->call(['data' => [
        'to_driver_id' => $this->driverB->id,
        'handover_km' => 1080,
        'reason' => DriverSwapReason::ShiftHandover->value,
    ]]);

    $swap = DriverSwap::where('trip_id', $this->trip->id)->sole();

    expect($swap->checkpoint)->not->toBeNull()
        ->and((float) $swap->checkpoint->km_reading)->toBe(1080.0);
});
