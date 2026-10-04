<?php

use App\Enums\AssignmentEndReason;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripDriverAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runAssignmentBackfill(): void
{
    $migration = require database_path('migrations/2026_10_04_234314_backfill_trip_driver_assignments.php');
    $migration->up();
}

test('a completed trip without swaps gets one closed assignment', function () {
    $trip = Trip::factory()->create([
        'status' => TripStatus::Completed,
        'started_at' => now()->subHours(3),
        'completed_at' => now()->subHour(),
    ]);

    runAssignmentBackfill();

    $assignments = TripDriverAssignment::where('trip_id', $trip->id)->get();
    expect($assignments)->toHaveCount(1)
        ->and($assignments[0]->driver_id)->toBe($trip->driver_id)
        ->and($assignments[0]->ended_at->equalTo($trip->completed_at))->toBeTrue()
        ->and($assignments[0]->end_reason)->toBe(AssignmentEndReason::TripFinished);
});

test('a finished trip with one swap gets two consecutive assignments', function () {
    $driverA = User::factory()->create();
    $driverB = User::factory()->create();
    $trip = Trip::factory()->create([
        'driver_id' => $driverB->id,
        'status' => TripStatus::Completed,
        'started_at' => now()->subHours(5),
        'completed_at' => now()->subHour(),
    ]);
    DB::table('driver_swaps')->insert([
        'trip_id' => $trip->id,
        'from_driver_id' => $driverA->id,
        'to_driver_id' => $driverB->id,
        'reason' => 'shift_handover',
        'created_by' => $driverA->id,
        'created_at' => now()->subHours(3),
    ]);

    runAssignmentBackfill();

    $assignments = TripDriverAssignment::where('trip_id', $trip->id)->orderBy('started_at')->get();
    expect($assignments)->toHaveCount(2)
        ->and($assignments[0]->driver_id)->toBe($driverA->id)
        ->and($assignments[0]->end_reason)->toBe(AssignmentEndReason::ShiftHandover)
        ->and($assignments[1]->driver_id)->toBe($driverB->id)
        ->and($assignments[1]->started_at->equalTo($assignments[0]->ended_at))->toBeTrue()
        ->and($assignments[1]->ended_at)->not->toBeNull();
});

test('a trip waiting in driver_swap has no open assignment and remembers its progress', function () {
    $trip = Trip::factory()->create([
        'status' => TripStatus::DriverSwap,
        'started_at' => now()->subHours(2),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => 'left_pickup',
        'occurred_at' => now()->subHour(),
    ]);
    TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => 'driver_swap',
        'occurred_at' => now()->subMinutes(30),
    ]);
    $originalDriverId = $trip->driver_id;

    runAssignmentBackfill();

    $trip->refresh();
    expect($trip->driver_id)->toBeNull()
        ->and($trip->status_before_swap)->toBe(TripStatus::Delivering)
        ->and(TripDriverAssignment::where('trip_id', $trip->id)->whereNull('ended_at')->exists())->toBeFalse()
        ->and(TripDriverAssignment::where('trip_id', $trip->id)->value('driver_id'))->toBe($originalDriverId);
});

test('a pending trip gets an open assignment', function () {
    $trip = Trip::factory()->create(['status' => TripStatus::Pending]);

    runAssignmentBackfill();

    expect($trip->openDriverAssignment()->first()?->driver_id)->toBe($trip->driver_id);
});
