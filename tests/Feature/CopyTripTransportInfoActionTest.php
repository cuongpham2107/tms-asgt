<?php

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Actions\CopyTransportInfoAction;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
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

    $this->driver = User::factory()->create([
        'name' => 'Nguyễn Văn A',
        'phone' => '0987654321',
        'cccd' => '001090123456',
        'cccd_issue_date' => Carbon::create(2021, 5, 20),
        'date_of_birth' => Carbon::create(1990, 8, 15),
        'address' => 'Hà Nội',
    ]);
    $this->driver->assignRole($this->driverRole);

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-999.88',
        'vehicle_type' => VehicleType::Normal,
        'load_capacity' => 3.5,
        'registration_number' => 'DK-123456',
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::Running,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'TRIP-COPY-1',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Pending,
    ]);
});

test('CopyTransportInfoAction creates action with proper config', function () {
    $action = CopyTransportInfoAction::make();

    expect($action->getName())->toBe('copy_transport_info')
        ->and($action->getLabel())->toBe('Copy thông tin xe+lái')
        ->and($action->getIcon())->toBe('heroicon-o-clipboard');
});

test('CopyTransportInfoAction getTransportInfo formats vehicle and driver info correctly', function () {
    $info = CopyTransportInfoAction::getTransportInfo($this->trip);

    expect($info['vehicle'])->toContain('29C-999.88')
        ->and($info['vehicle'])->toContain('3,5T')
        ->and($info['vehicle'])->toContain('DK-123456')
        ->and($info['driver'])->toContain('Nguyễn Văn A')
        ->and($info['driver'])->toContain('0987654321')
        ->and($info['driver'])->toContain('001090123456')
        ->and($info['driver'])->toContain('20/05/2021')
        ->and($info['driver'])->toContain('15/08/1990')
        ->and($info['driver'])->toContain('Hà Nội');
});

test('CopyTransportInfoAction is hidden when trip has neither driver nor vehicle', function () {
    $emptyTrip = new Trip;

    $action = CopyTransportInfoAction::make();

    expect($action->record($emptyTrip)->isHidden())->toBeTrue()
        ->and($action->record($this->trip)->isHidden())->toBeFalse();
});

test('copy_transport_info action is present in trip table action group and can be mounted', function () {
    $admin = User::factory()->create();
    $adminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $admin->assignRole($adminRole);

    $this->actingAs($admin);

    Livewire::test(ListTrips::class)
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->assertTableActionExists('copy_transport_info', record: $this->trip)
        ->callTableAction('copy_transport_info', $this->trip)
        ->assertHasNoTableActionErrors()
        ->assertNotified('Đã copy thông tin');
});
