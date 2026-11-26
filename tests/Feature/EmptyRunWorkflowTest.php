<?php

use App\Enums\CheckpointType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Actions\CreateEmptyRunAction;
use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Http\Resources\TripResource;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Km\TripKmAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);

    $this->driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $this->admin = User::factory()->create();
    $this->driver = User::factory()->create(['is_active' => true]);
    $this->driver->assignRole($this->driverRole);
    $this->actingAs($this->admin);

    $this->vehicle = Vehicle::create([
        'plate_number' => '20H05304',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 445070,
    ]);

    $this->shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHours(2),
    ]);
});

test('empty run can be created without start/end locations when note is provided and does not pre-create null end checkpoint', function () {
    $action = CreateEmptyRunAction::make();
    $action->call(['data' => [
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'start_location_id' => null,
        'end_location_id' => null,
        'note' => 'Đi đổ dầu',
    ]]);

    $trip = Trip::where('driver_id', $this->driver->id)->where('is_empty_run', true)->first();

    expect($trip)->not->toBeNull()
        ->and($trip->status)->toBe(TripStatus::ReturnTrip)
        ->and($trip->start_location_id)->toBeNull()
        ->and($trip->end_location_id)->toBeNull()
        ->and($trip->note)->toBe('Đi đổ dầu')
        ->and($trip->checkpoints()->count())->toBe(1)
        ->and($trip->checkpoints()->first()->checkpoint_type)->toBe(CheckpointType::Started);

    $resource = TripResource::make($trip->load(['startLocation', 'endLocation', 'orders']))->toArray(new Request);
    expect($resource['route'])->toBe('Đi đổ dầu');
});

test('reporting and resolving km issue on empty run updates start_km and started checkpoint', function () {
    $trip = Trip::create([
        'trip_code' => 'CD-EMPTY-KM-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'status' => TripStatus::ReturnTrip,
        'is_empty_run' => true,
        'note' => 'Đi đổ dầu',
        'started_at' => now()->subMinutes(5),
        'start_km' => 445070,
    ]);

    $startedCp = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->subMinutes(5),
        'km_reading' => 445070,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'vehicle_id' => $this->vehicle->id,
    ]);

    // Even if a legacy null-km end checkpoint exists, report targets started checkpoint
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::End->value,
        'occurred_at' => now()->subMinutes(4),
        'km_reading' => null,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'vehicle_id' => $this->vehicle->id,
    ]);

    $this->actingAs($this->driver, 'sanctum')
        ->postJson("/api/driver/trips/{$trip->id}/report-km-issue", [
            'reported_km' => 445080,
            'note' => 'Gõ nhầm số km',
        ])
        ->assertCreated();

    $report = TripKmReport::where('trip_id', $trip->id)->latest('id')->first();
    expect($report)->not->toBeNull()
        ->and($report->checkpoint_id)->toBe($startedCp->id);

    app(TripKmAdjustmentService::class)->resolveReport($report, 445080.0, null, null, $this->admin->id);

    $trip->refresh();
    expect((float) $trip->start_km)->toBe(445080.0)
        ->and((float) $startedCp->fresh()->km_reading)->toBe(445080.0)
        ->and((float) $this->vehicle->fresh()->current_mileage)->toBe(445080.0)
        ->and((float) ($trip->total_km ?? 0))->toBe(0.0);

    $trip->complete(endKm: 445095.0);
    $trip->refresh();

    expect($trip->status)->toBe(TripStatus::Completed)
        ->and((float) $trip->end_km)->toBe(445095.0)
        ->and((float) $trip->total_km)->toBe(15.0)
        ->and((float) $trip->checkpoints()->where('checkpoint_type', CheckpointType::End->value)->value('km_reading'))->toBe(445095.0);
});

test('admin can edit start/end locations, note, and checkpoints of an empty run on web', function () {
    $locA = Location::create(['code' => 'ALSC', 'name' => 'ALSC', 'is_active' => true]);
    $locB = Location::create(['code' => 'CLC', 'name' => 'CLC', 'is_active' => true]);

    $trip = Trip::create([
        'trip_code' => 'CD-EMPTY-EDIT-01',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'status' => TripStatus::ReturnTrip,
        'is_empty_run' => true,
        'start_location_id' => $locA->id,
        'end_location_id' => $locB->id,
        'note' => 'Về bãi',
        'started_at' => now()->subMinutes(10),
        'start_km' => 445070,
    ]);

    $startedCp = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now()->subMinutes(10),
        'km_reading' => 445070,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'vehicle_id' => $this->vehicle->id,
    ]);

    Livewire::test(ListTrips::class, ['orderType' => 'all', 'activePlaceFilter' => 'all', 'activeStatusFilter' => 'all'])
        ->loadTable()
        ->mountTableAction('edit', $trip)
        ->set('mountedActions.0.data.start_location_id', null)
        ->set('mountedActions.0.data.end_location_id', null)
        ->set('mountedActions.0.data.note', 'Đi đổ dầu')
        ->set("mountedActions.0.data.checkpoints.record-{$startedCp->id}.km_reading", 445080)
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $trip->refresh();
    expect($trip->status)->toBe(TripStatus::ReturnTrip)
        ->and($trip->start_location_id)->toBeNull()
        ->and($trip->end_location_id)->toBeNull()
        ->and($trip->note)->toBe('Đi đổ dầu')
        ->and((float) $trip->start_km)->toBe(445080.0)
        ->and((float) $startedCp->fresh()->km_reading)->toBe(445080.0);
});
