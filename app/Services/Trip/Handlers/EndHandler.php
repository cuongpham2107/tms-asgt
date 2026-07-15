<?php

namespace App\Services\Trip\Handlers;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\Vehicle;
use App\Services\ShiftKmCalculatorService;
use App\Services\Trip\CheckpointFactory;
use App\Services\TripKmCalculatorService;
use Illuminate\Support\Facades\DB;

class EndHandler implements CheckpointHandlerInterface
{
    public function handle(DriverShift $shift, Vehicle $vehicle, float $kmReading): TripCheckpoint
    {
        return DB::transaction(function () use ($shift, $vehicle, $kmReading) {
            // 1. Find active trip on this vehicle in this shift
            $activeTrip = Trip::where('vehicle_id', $vehicle->id)
                ->where('shift_id', $shift->id)
                ->whereNotIn('status', [TripStatus::Completed, TripStatus::DriverSwap])
                ->first();

            $activeTripId = null;

            if ($activeTrip !== null) {
                // Trip chưa hoàn thành — driver_swap giữa chừng
                app(TripKmCalculatorService::class)->calculate($activeTrip, endKm: $kmReading);
                $activeTrip->refresh();

                if ($activeTrip->shift) {
                    app(ShiftKmCalculatorService::class)->calculate($activeTrip->shift);
                }

                $activeTrip->end_km = $kmReading;
                $activeTrip->status = TripStatus::DriverSwap;
                $activeTrip->save();

                $activeTrip->orders()
                    ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value])
                    ->update(['status' => OrderStatus::DriverSwap->value]);

                $activeTripId = $activeTrip->id;
            }

            // 2. Create TripCheckpoint(s)
            if ($activeTripId !== null) {
                $checkpoints = app(CheckpointFactory::class)->create(
                    $activeTrip,
                    ['occurred_at' => now(), 'km_reading' => $kmReading],
                    CheckpointType::DriverSwap,
                );
                $checkpoint = $checkpoints->first();
            } else {
                $checkpoint = TripCheckpoint::create([
                    'checkpoint_type' => CheckpointType::End->value,
                    'trip_id' => null,
                    'shift_id' => $shift->id,
                    'driver_id' => $shift->driver_id,
                    'km_reading' => $kmReading,
                    'occurred_at' => now(),
                ]);
            }

            // 3. Update vehicle mileage — critical for Bug 1 fix
            $vehicle->current_mileage = $kmReading;
            $vehicle->save();

            return $checkpoint;
        });
    }
}
