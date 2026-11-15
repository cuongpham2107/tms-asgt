<?php

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $this->companyDriver = User::factory()->create(['name' => 'Tài Xế Công Ty']);
    $this->companyDriver->assignRole($driverRole);

    $this->admin = User::factory()->create();
    foreach (['ViewAny:Trip', 'View:Trip', 'Update:Trip'] as $permission) {
        $this->admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }
    $this->actingAs($this->admin);

    $this->rentedVehicle = Vehicle::create([
        'plate_number' => '15H-08970',
        'owner' => 'ASGS',
        'type' => VehicleOwnerType::Rent,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'external_driver_name' => 'Tài Xế Cũ',
        'is_active' => true,
    ]);

    $this->companyVehicle = Vehicle::create([
        'plate_number' => '29C-00001',
        'owner' => 'ASGT',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);
});

test('trip snapshots rented vehicle external_driver_name and keeps history when vehicle changes driver', function () {
    $oldTrip = Trip::create([
        'trip_code' => 'TRIP-SNAP-1',
        'vehicle_id' => $this->rentedVehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-10 08:00'),
        'completed_at' => Carbon::parse('2026-11-10 10:00'),
    ]);

    expect($oldTrip->external_driver_name)->toBe('Tài Xế Cũ')
        ->and($oldTrip->resolveDriverName())->toBe('Tài Xế Cũ');

    // Sau này xe thuê đổi tài xế trên bảng vehicles
    $this->rentedVehicle->update(['external_driver_name' => 'Tài Xế Mới']);

    $newTrip = Trip::create([
        'trip_code' => 'TRIP-SNAP-2',
        'vehicle_id' => $this->rentedVehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-15 08:00'),
        'completed_at' => Carbon::parse('2026-11-15 10:00'),
    ]);

    expect($oldTrip->fresh()->resolveDriverName())->toBe('Tài Xế Cũ')
        ->and($newTrip->external_driver_name)->toBe('Tài Xế Mới')
        ->and($newTrip->resolveDriverName())->toBe('Tài Xế Mới');
});

test('reassigning trip vehicle or company driver updates or clears external_driver_name snapshot', function () {
    $otherRented = Vehicle::create([
        'plate_number' => '15H-09876',
        'owner' => 'ASGS',
        'type' => VehicleOwnerType::Rent,
        'vehicle_type' => VehicleType::Normal,
        'external_driver_name' => 'Tài Xế Xe Khác',
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-REASSIGN-1',
        'vehicle_id' => $this->rentedVehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Pending,
    ]);

    expect($trip->external_driver_name)->toBe('Tài Xế Cũ');

    $trip->update(['vehicle_id' => $otherRented->id]);
    expect($trip->fresh()->external_driver_name)->toBe('Tài Xế Xe Khác');

    $trip->update([
        'vehicle_id' => $this->companyVehicle->id,
        'driver_id' => $this->companyDriver->id,
    ]);
    expect($trip->fresh()->external_driver_name)->toBeNull()
        ->and($trip->fresh()->resolveDriverName())->toBe('Tài Xế Công Ty');
});

test('edit trip form shows external_driver_name and hides driver_id for rented vehicles and vice versa for company vehicles', function () {
    $rentTrip = Trip::create([
        'trip_code' => 'TRIP-FORM-RENT',
        'vehicle_id' => $this->rentedVehicle->id,
        'driver_id' => null,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-15 08:00'),
        'completed_at' => Carbon::parse('2026-11-15 09:00'),
    ]);

    $companyTrip = Trip::create([
        'trip_code' => 'TRIP-FORM-COMP',
        'vehicle_id' => $this->companyVehicle->id,
        'driver_id' => $this->companyDriver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse('2026-11-15 08:00'),
        'completed_at' => Carbon::parse('2026-11-15 09:00'),
    ]);

    Livewire::test(ListTrips::class, [
        'orderType' => 'all',
        'activePlaceFilter' => 'all',
        'activeStatusFilter' => 'all',
    ])
        ->loadTable()
        ->mountTableAction('edit', $rentTrip)
        ->assertFormFieldHidden('driver_id')
        ->assertFormFieldVisible('external_driver_name')
        ->fillForm(['external_driver_name' => 'Tài Xế Riêng Chuyến Này'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($rentTrip->fresh()->external_driver_name)->toBe('Tài Xế Riêng Chuyến Này')
        ->and($this->rentedVehicle->fresh()->external_driver_name)->toBe('Tài Xế Cũ');

    Livewire::test(ListTrips::class, [
        'orderType' => 'all',
        'activePlaceFilter' => 'all',
        'activeStatusFilter' => 'all',
    ])
        ->loadTable()
        ->mountTableAction('edit', $companyTrip)
        ->assertFormFieldVisible('driver_id')
        ->assertFormFieldHidden('external_driver_name');
});
