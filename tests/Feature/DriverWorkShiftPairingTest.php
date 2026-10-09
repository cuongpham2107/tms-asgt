<?php

declare(strict_types=1);

use App\Enums\DriverWorkShift;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShiftScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('determines correct work shift based on 8am cut-off', function (): void {
    // 9th October at 07:59:59 -> Shift belongs to 8th October (Even)
    $before8am = Carbon::create(2026, 10, 9, 7, 59, 59);
    expect(ShiftScheduleService::determineShift($before8am))->toBe(DriverWorkShift::Even)
        ->and(ShiftScheduleService::isTodayShift(DriverWorkShift::Even, $before8am))->toBeTrue()
        ->and(ShiftScheduleService::isTodayShift(DriverWorkShift::Odd, $before8am))->toBeFalse();

    // 9th October at 08:00:00 -> Shift belongs to 9th October (Odd)
    $after8am = Carbon::create(2026, 10, 9, 8, 0, 0);
    expect(ShiftScheduleService::determineShift($after8am))->toBe(DriverWorkShift::Odd)
        ->and(ShiftScheduleService::isTodayShift(DriverWorkShift::Odd, $after8am))->toBeTrue()
        ->and(ShiftScheduleService::isTodayShift(DriverWorkShift::Even, $after8am))->toBeFalse();

    // 31st October at 10:00 -> Shift belongs to 31st (Odd)
    $oct31 = Carbon::create(2026, 10, 31, 10, 0, 0);
    expect(ShiftScheduleService::determineShift($oct31))->toBe(DriverWorkShift::Odd);

    // 1st November at 10:00 -> Shift belongs to 1st (Odd)
    $nov1 = Carbon::create(2026, 11, 1, 10, 0, 0);
    expect(ShiftScheduleService::determineShift($nov1))->toBe(DriverWorkShift::Odd);
});

test('syncs driver assignment two-way between vehicle and users', function (): void {
    $evenDriver = User::factory()->create([
        'name' => 'Lái Xe Ca Chẵn',
    ]);

    $oddDriver = User::factory()->create([
        'name' => 'Lái Xe Ca Lẻ',
    ]);

    $vehicle = Vehicle::factory()->create([
        'plate_number' => '29C-99999',
    ]);

    // Assign on Vehicle
    $vehicle->update([
        'even_driver_id' => $evenDriver->id,
        'odd_driver_id' => $oddDriver->id,
    ]);

    expect($evenDriver->fresh()->vehicle_id)->toBe($vehicle->id)
        ->and($evenDriver->fresh()->work_shift)->toBe(DriverWorkShift::Even)
        ->and($oddDriver->fresh()->vehicle_id)->toBe($vehicle->id)
        ->and($oddDriver->fresh()->work_shift)->toBe(DriverWorkShift::Odd);

    // Resolve drivers on vehicle
    expect($vehicle->evenDriver->id)->toBe($evenDriver->id)
        ->and($vehicle->oddDriver->id)->toBe($oddDriver->id)
        ->and($vehicle->getDriverForShift(DriverWorkShift::Even)->id)->toBe($evenDriver->id)
        ->and($vehicle->getDriverForShift(DriverWorkShift::Odd)->id)->toBe($oddDriver->id);

    // Test driver for today at specific time
    Carbon::setTestNow(Carbon::create(2026, 10, 9, 10, 0, 0)); // Day 9 -> Odd shift
    expect($vehicle->getDriverForToday()->id)->toBe($oddDriver->id);

    Carbon::setTestNow(Carbon::create(2026, 10, 10, 10, 0, 0)); // Day 10 -> Even shift
    expect($vehicle->getDriverForToday()->id)->toBe($evenDriver->id);

    // Test assigning from User side
    $newDriver = User::factory()->create(['name' => 'Lái Xe Mới']);
    $newVehicle = Vehicle::factory()->create(['plate_number' => '29C-88888']);

    // Assign vehicle and even shift on user
    $newDriver->update([
        'vehicle_id' => $newVehicle->id,
        'work_shift' => DriverWorkShift::Even,
    ]);

    expect($newVehicle->fresh()->even_driver_id)->toBe($newDriver->id)
        ->and($newVehicle->fresh()->odd_driver_id)->toBeNull();

    // Change shift to odd on user
    $newDriver->update([
        'work_shift' => DriverWorkShift::Odd,
    ]);

    expect($newVehicle->fresh()->odd_driver_id)->toBe($newDriver->id)
        ->and($newVehicle->fresh()->even_driver_id)->toBeNull();

    Carbon::setTestNow(); // Reset Carbon
});
