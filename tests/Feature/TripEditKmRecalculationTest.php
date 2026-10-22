<?php

use App\Enums\CheckpointType;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripKmAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($role);
    $this->admin = User::factory()->create();
    foreach (['ViewAny:Trip', 'View:Trip', 'Update:Trip'] as $permission) {
        $this->admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
    }
    $this->actingAs($this->admin);

    $this->vehicle = Vehicle::create([
        'plate_number' => 'EDIT-001',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 102000,
    ]);

    $this->oldTrip = Trip::create([
        'trip_code' => 'TRIP-OLD',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
        'start_km' => 100000,
        'end_km' => 100120,
        'started_at' => now()->subDays(3),
    ]);

    $checkpoint = fn (Trip $trip, CheckpointType $type, int $km, $at) => TripCheckpoint::create([
        'trip_id' => $trip->id, 'driver_id' => $this->driver->id,
        'checkpoint_type' => $type, 'km_reading' => $km, 'occurred_at' => $at,
    ]);

    $checkpoint($this->oldTrip, CheckpointType::Started, 100000, now()->subDays(3)->setSeconds(17));
    $this->pickupCheckpoint = $checkpoint($this->oldTrip, CheckpointType::ArrivedPickup, 100050, now()->subDays(3)->addHour()->setSeconds(23));
    $this->deliveryCheckpoint = $checkpoint($this->oldTrip, CheckpointType::ArrivedDelivery, 100120, now()->subDays(3)->addHours(2)->setSeconds(41));

    $this->newTrip = Trip::create([
        'trip_code' => 'TRIP-NEW',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Delivering,
        'start_km' => 101800,
        'end_km' => 102000,
        'started_at' => now()->subHours(2),
    ]);
    $checkpoint($this->newTrip, CheckpointType::Started, 101800, now()->subHours(2));
});

test('recalculating an old trip leaves vehicle mileage alone', function () {
    TripCheckpoint::where('trip_id', $this->oldTrip->id)->where('checkpoint_type', CheckpointType::ArrivedDelivery)->update(['km_reading' => 100150]);

    app(TripKmAdjustmentService::class)->recalculateAfterEdit($this->oldTrip->fresh());

    expect((float) $this->oldTrip->fresh()->end_km)->toBe(100150.0)
        ->and((float) $this->oldTrip->fresh()->total_km)->toBe(150.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(102000.0);
});

test('recalculating the latest trip moves vehicle mileage to its final km, even downwards', function () {
    $this->vehicle->update(['current_mileage' => 150000]); // từng bị nhập lố
    TripCheckpoint::create([
        'trip_id' => $this->newTrip->id, 'driver_id' => $this->driver->id,
        'checkpoint_type' => CheckpointType::Completed, 'km_reading' => 102000, 'occurred_at' => now()->subHour(),
    ]);

    app(TripKmAdjustmentService::class)->recalculateAfterEdit($this->newTrip->fresh());

    expect((float) $this->vehicle->fresh()->current_mileage)->toBe(102000.0);
});

test('start and end km are taken from the earliest and latest checkpoints', function () {
    $this->oldTrip->updateQuietly(['start_km' => 99000, 'end_km' => 999999]);

    app(TripKmAdjustmentService::class)->recalculateAfterEdit($this->oldTrip->fresh());

    $trip = $this->oldTrip->fresh();
    expect((float) $trip->start_km)->toBe(100000.0)
        ->and((float) $trip->end_km)->toBe(100120.0)
        ->and((float) $trip->total_km)->toBe(120.0);
});

test('km snapshot ignores formatting noise but sees a km change', function () {
    $service = app(TripKmAdjustmentService::class);
    $before = $service->kmSnapshot($this->oldTrip);

    TripCheckpoint::where('trip_id', $this->oldTrip->id)->update(['occurred_at' => DB::raw("strftime('%Y-%m-%d %H:%M:00', occurred_at)")]);
    expect($service->kmSnapshot($this->oldTrip))->toBe($before);

    TripCheckpoint::where('trip_id', $this->oldTrip->id)->where('checkpoint_type', CheckpointType::ArrivedPickup)->update(['km_reading' => 100060]);
    expect($service->kmSnapshot($this->oldTrip))->not->toBe($before);
});

test('closing pending reports after an edit resolves them and unpauses the trip', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->oldTrip->id, 'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
        'reported_km' => 100150, 'system_km' => 100120, 'status' => TripKmReportStatus::Pending,
    ]);
    $this->oldTrip->update(['is_paused' => true, 'paused_reason' => 'Chờ điều chỉnh báo sai số Km']);

    app(TripKmAdjustmentService::class)->closePendingReportsAfterEdit($this->oldTrip->fresh(), $this->admin->id);

    expect($report->fresh()->status)->toBe(TripKmReportStatus::Resolved)
        ->and($report->fresh()->resolved_by)->toBe($this->admin->id)
        ->and($report->fresh()->admin_note)->toBe('Đã sửa km qua form sửa chuyến')
        ->and($this->oldTrip->fresh()->is_paused)->toBeFalse();
});

function editTripTable()
{
    return Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all']);
}

test('editing a trip without km changes does not recalculate', function () {
    $this->oldTrip->updateQuietly(['total_km' => 999]);

    editTripTable()->callTableAction('edit', $this->oldTrip, data: ['trip_code' => 'TRIP-OLD-RENAMED'])
        ->assertHasNoTableActionErrors();

    expect((float) $this->oldTrip->fresh()->total_km)->toBe(999.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(102000.0);
});

test('editing checkpoint km of an old trip recalculates it without touching vehicle km', function () {
    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['checkpoints.record-'.$this->deliveryCheckpoint->id.'.km_reading' => 100150])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect((float) $this->oldTrip->fresh()->total_km)->toBe(150.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(102000.0);
});

test('editing km closes a pending km report', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->oldTrip->id, 'driver_id' => $this->driver->id, 'vehicle_id' => $this->vehicle->id,
        'reported_km' => 100150, 'system_km' => 100120, 'status' => TripKmReportStatus::Pending,
    ]);

    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['checkpoints.record-'.$this->deliveryCheckpoint->id.'.km_reading' => 100150])
        ->callMountedTableAction();

    expect($report->fresh()->status)->toBe(TripKmReportStatus::Resolved);
});

test('start and end km inputs are read-only and not saved from the form', function () {
    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->assertTableActionDataSet(['start_km' => 100000.0])
        ->fillForm(['end_km' => 555555])
        ->callMountedTableAction();

    expect((float) $this->oldTrip->fresh()->end_km)->toBe(100120.0);
});

test('saving checkpoints whose km goes backwards in time is rejected', function () {
    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['checkpoints.record-'.$this->pickupCheckpoint->id.'.km_reading' => 10050])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['checkpoints']);

    expect((float) $this->pickupCheckpoint->fresh()->km_reading)->toBe(100050.0);
});

test('checkpoints at the same minute may carry different km', function () {
    $this->pickupCheckpoint->update(['occurred_at' => $this->deliveryCheckpoint->occurred_at]);

    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['trip_code' => 'SAME-MINUTE'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();
});

test('editing completed_at completes the trip and recalculates end km from the latest checkpoint', function () {
    $this->oldTrip->updateQuietly(['end_km' => null, 'total_km' => null]);

    editTripTable()->mountTableAction('edit', $this->oldTrip)
        ->fillForm(['completed_at' => now()->subMinutes(30)])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip = $this->oldTrip->fresh();
    expect($trip->status)->toBe(TripStatus::Completed)
        ->and((float) $trip->end_km)->toBe(100120.0)
        ->and((float) $trip->total_km)->toBe(120.0);
});
