<?php

namespace App\Observers;

use App\Enums\CheckpointType;
use App\Events\TripUpdated;
use App\Models\TripCheckpoint;
use Illuminate\Support\Facades\DB;
use Throwable;

class TripCheckpointObserver
{
    /**
     * Handle the TripCheckpoint "created" event.
     */
    public function created(TripCheckpoint $checkpoint): void
    {
        $checkpoint->loadMissing('trip.driver', 'trip.vehicle');

        if ($checkpoint->trip) {
            $trip = $checkpoint->trip;
            $broadcast = function () use ($trip): void {
                try {
                    TripUpdated::dispatch($trip, 'checkpoint');
                } catch (Throwable) {
                }
            };

            if (app()->runningUnitTests()) {
                $broadcast();
            } else {
                DB::afterCommit($broadcast);
            }
        }
    }

    /**
     * Một lần bàn giao có nhiều mốc driver_swap (mỗi đơn một mốc). Sửa km/giờ của một mốc thì đồng bộ cả nhóm.
     */
    public function updated(TripCheckpoint $checkpoint): void
    {
        if ($checkpoint->checkpoint_type !== CheckpointType::DriverSwap
            || ! $checkpoint->wasChanged(['km_reading', 'occurred_at'])) {
            return;
        }

        TripCheckpoint::query()
            ->where('trip_id', $checkpoint->trip_id)
            ->where('checkpoint_type', CheckpointType::DriverSwap)
            ->where('id', '!=', $checkpoint->id)
            ->where('occurred_at', $checkpoint->getOriginal('occurred_at'))
            ->update([
                'km_reading' => $checkpoint->km_reading,
                'occurred_at' => $checkpoint->occurred_at,
            ]);
    }
}
