<?php

use App\Enums\CheckpointType;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripKmAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($role);
    $this->admin = User::factory()->create();

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
