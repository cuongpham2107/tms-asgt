<?php

namespace App\Services\Shift;

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\Trip\CheckpointFactory;
use App\Services\Trip\DriverSwapService;
use Illuminate\Support\Facades\DB;

class LeaveVehicleService
{
    public function handle(DriverShift $shift, Vehicle $vehicle, float $kmReading): TripCheckpoint
    {
        return DB::transaction(function () use ($shift, $vehicle, $kmReading) {
            // 1. Find active trip on this vehicle in this shift
            $activeTrip = Trip::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('shift_id', $shift->id)
                ->whereNotIn('status', [
                    TripStatus::Completed,
                    TripStatus::DriverSwap,
                    TripStatus::Cancelled,
                    TripStatus::Pending,
                ], 'and')
                ->first();

            $activeTripId = null;

            if ($activeTrip !== null) {
                // Trip chưa hoàn thành — driver_swap giữa chừng
                app(DriverSwapService::class)->handOverWithKm($activeTrip, $kmReading);

                $activeTripId = $activeTrip->id;
            }

            // 2. Create TripCheckpoint(s)
            $checkpoint = null;

            if ($activeTripId !== null) {
                $checkpoints = app(CheckpointFactory::class)->create(
                    $activeTrip,
                    ['occurred_at' => now(), 'km_reading' => $kmReading],
                    CheckpointType::DriverSwap,
                );
                $checkpoint = $checkpoints->first();
            }

            // Fallback: trip đang chạy không có đơn (empty run) hoặc không tạo được checkpoint
            // → vẫn phải ghi checkpoint 'end' cho ca, tránh trả null (500 TypeError)
            // và tránh việc ca không có km kết thúc.
            $checkpoint ??= TripCheckpoint::create([
                'checkpoint_type' => CheckpointType::End->value,
                'trip_id' => null,
                'shift_id' => $shift->id,
                'driver_id' => $shift->driver_id,
                'km_reading' => $kmReading,
                'occurred_at' => now(),
            ]);

            // 3. Update vehicle mileage — critical for Bug 1 fix
            $vehicle->current_mileage = $kmReading;
            $vehicle->save();

            return $checkpoint;
        });
    }
}
