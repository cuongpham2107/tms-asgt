<?php

use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Shift\ShiftEndService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('closing a shift records the end and releases handed-over and waiting trips', function () {
    $driver = User::factory()->create();
    $vehicle = Vehicle::create(['plate_number' => '29C-END', 'vehicle_type' => VehicleType::Normal, 'owner' => 'CLC', 'is_active' => true, 'status' => VehicleStatus::On, 'type' => VehicleOwnerType::Company, 'current_mileage' => 1000]);
    $shift = DriverShift::create(['driver_id' => $driver->id, 'shift_type' => ShiftType::Full, 'start_time' => now()->subHours(8), 'start_km' => 1000]);
    $trip = fn (string $code, TripStatus $status) => Trip::create(['trip_code' => $code, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'shift_id' => $shift->id, 'status' => $status]);
    $swapped = $trip('T-SWAP', TripStatus::DriverSwap);
    $waiting = $trip('T-WAIT', TripStatus::Pending);
    $done = $trip('T-DONE', TripStatus::Completed);
    $endTime = now()->subMinute()->startOfMinute();

    app(ShiftEndService::class)->close($shift, $endTime, 1080.0, ['end_gps_lat' => '21.1', 'end_gps_lng' => '105.8']);

    $shift->refresh();
    expect($shift->end_time->equalTo($endTime))->toBeTrue()
        ->and((float) $shift->end_km)->toBe(1080.0)
        ->and((float) $shift->end_gps_lat)->toBe(21.1)
        ->and($swapped->fresh()->shift_id)->toBeNull()
        ->and($waiting->fresh()->shift_id)->toBeNull()
        ->and($done->fresh()->shift_id)->toBe($shift->id);
});
