<?php

use App\Enums\ShiftType;
use App\Models\DriverShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('driver shift filter can filter by driver and status', function () {
    $driver1 = User::factory()->create(['name' => 'Tài xế A']);
    $driver2 = User::factory()->create(['name' => 'Tài xế B']);

    $runningShift = DriverShift::create([
        'driver_id' => $driver1->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHours(2),
        'end_time' => null,
    ]);

    $endedShift = DriverShift::create([
        'driver_id' => $driver2->id,
        'shift_type' => ShiftType::MorningHalf,
        'start_time' => now()->subDays(1),
        'end_time' => now()->subDays(1)->addHours(8),
    ]);

    expect(DriverShift::whereNull('end_time')->count())->toBe(1)
        ->and(DriverShift::whereNotNull('end_time')->count())->toBe(1);
});

test('driver shifts with null end_time are ordered first', function () {
    $driver = User::factory()->create();

    $endedShift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subDays(2),
        'end_time' => now()->subDays(2)->addHours(8),
    ]);

    $runningShift = DriverShift::create([
        'driver_id' => $driver->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now()->subHours(1),
        'end_time' => null,
    ]);

    $query = DriverShift::query()
        ->orderByRaw('CASE WHEN end_time IS NULL THEN 0 ELSE 1 END ASC')
        ->orderBy('start_time', 'desc');

    $shifts = $query->get();

    expect($shifts->first()->id)->toBe($runningShift->id)
        ->and($shifts->last()->id)->toBe($endedShift->id);
});
