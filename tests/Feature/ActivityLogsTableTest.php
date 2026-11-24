<?php

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

test('activity log subject title renders checkpoint type label', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '29C-800.01',
        'owner' => 'ASGT',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $trip = Trip::create([
        'trip_code' => 'TRIP-ACTIVITY-01',
        'vehicle_id' => $vehicle->id,
        'status' => TripStatus::Pending,
    ]);

    $checkpoint = TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::Started->value,
        'occurred_at' => now(),
    ]);

    $activity = Activity::create([
        'subject_type' => TripCheckpoint::class,
        'subject_id' => $checkpoint->id,
        'description' => 'updated',
    ]);

    expect(ActivityLogsTable::formatSubjectTitle($activity))
        ->toBe('Mốc hành trình #'.$checkpoint->id.' (Bắt đầu chuyến)');
});

test('deletion context note is recorded only for deleted events', function () {
    $vehicle = Vehicle::create([
        'plate_number' => '29C-800.02',
        'owner' => 'ASGT',
        'type' => VehicleOwnerType::Company,
        'vehicle_type' => VehicleType::Normal,
        'status' => VehicleStatus::On,
        'is_active' => true,
    ]);

    $createdLog = Activity::query()
        ->where('subject_type', Vehicle::class)
        ->where('subject_id', $vehicle->id)
        ->where('event', 'created')
        ->latest('id')
        ->first();

    expect($createdLog)->not->toBeNull()
        ->and($createdLog->description)->toBe('created')
        ->and($createdLog->properties->has('deletion_context'))->toBeFalse();

    $vehicle->delete();

    $deletedLog = Activity::query()
        ->where('subject_type', Vehicle::class)
        ->where('subject_id', $vehicle->id)
        ->where('event', 'deleted')
        ->latest('id')
        ->first();

    expect($deletedLog)->not->toBeNull()
        ->and($deletedLog->description)->not->toBe('deleted')
        ->and($deletedLog->properties->has('deletion_context'))->toBeTrue()
        ->and($deletedLog->properties['deletion_context'])->toHaveKeys([
            'note',
            'channel',
            'trigger',
            'page_url',
            'request_path',
            'ip',
            'user_agent',
            'app_trace',
        ]);
});
