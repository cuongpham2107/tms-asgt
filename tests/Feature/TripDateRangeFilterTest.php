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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-12345',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
    ]);

    $this->actingAs($this->admin);
});

function makeTrip(string $code, ?string $startedAt, string $createdAt, TripStatus $status = TripStatus::Started): Trip
{
    $trip = Trip::create([
        'trip_code' => $code,
        'vehicle_id' => test()->vehicle->id,
        'status' => $status,
        'started_at' => $startedAt,
    ]);

    DB::table('trips')->where('id', $trip->id)->update([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $trip->refresh();
}

test('date range includes draft trips whose started_at is still null', function () {
    $draft = makeTrip('TRIP-DRAFT', null, '2026-10-10 08:00:00', TripStatus::Pending);
    $running = makeTrip('TRIP-RUNNING', '2026-10-10 09:00:00', '2026-10-10 07:00:00');
    $outside = makeTrip('TRIP-OUTSIDE', '2026-09-01 09:00:00', '2026-09-01 07:00:00');

    Livewire::test(ListTrips::class)->loadTable()
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->set('activeStatusFilter', 'all')
        ->set('dateFrom', '2026-10-01')
        ->set('dateTo', '2026-10-31')
        ->assertCanSeeTableRecords([$draft, $running])
        ->assertCanNotSeeTableRecords([$outside]);
});

test('date range works when only the from date is selected', function () {
    $draft = makeTrip('TRIP-DRAFT', null, '2026-10-10 08:00:00', TripStatus::Pending);
    $running = makeTrip('TRIP-RUNNING', '2026-10-10 09:00:00', '2026-10-10 07:00:00');
    $outside = makeTrip('TRIP-OUTSIDE', '2026-09-01 09:00:00', '2026-09-01 07:00:00');

    Livewire::test(ListTrips::class)->loadTable()
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->set('activeStatusFilter', 'all')
        ->set('dateFrom', '2026-10-01')
        ->set('dateTo', null)
        ->assertCanSeeTableRecords([$draft, $running])
        ->assertCanNotSeeTableRecords([$outside]);
});

test('date range works when only the to date is selected', function () {
    $draft = makeTrip('TRIP-DRAFT', null, '2026-10-10 08:00:00', TripStatus::Pending);
    $running = makeTrip('TRIP-RUNNING', '2026-10-10 09:00:00', '2026-10-10 07:00:00');
    $outside = makeTrip('TRIP-OUTSIDE', '2026-11-01 09:00:00', '2026-11-01 07:00:00');

    Livewire::test(ListTrips::class)->loadTable()
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->set('activeStatusFilter', 'all')
        ->set('dateFrom', null)
        ->set('dateTo', '2026-10-31')
        ->assertCanSeeTableRecords([$draft, $running])
        ->assertCanNotSeeTableRecords([$outside]);
});

test('status counts respect the date range and include draft trips', function () {
    $draft = makeTrip('TRIP-DRAFT', null, '2026-10-10 08:00:00', TripStatus::Pending);
    makeTrip('TRIP-OUTSIDE', '2026-09-01 09:00:00', '2026-09-01 07:00:00');

    Livewire::test(ListTrips::class)->loadTable()
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->set('activeStatusFilter', 'all')
        ->set('dateFrom', '2026-10-01')
        ->set('dateTo', '2026-10-31')
        ->assertSet('dateFrom', '2026-10-01')
        ->assertSee('1');
});

test('date bounds are inclusive of the whole end day', function () {
    Carbon::setTestNow('2026-10-31 15:00:00');

    $lateTrip = makeTrip('TRIP-LATE', '2026-10-31 23:30:00', '2026-10-31 07:00:00');

    Livewire::test(ListTrips::class)->loadTable()
        ->set('orderType', 'all')
        ->set('activePlaceFilter', 'all')
        ->set('activeStatusFilter', 'all')
        ->set('dateFrom', '2026-10-31')
        ->set('dateTo', '2026-10-31')
        ->assertCanSeeTableRecords([$lateTrip]);
});
