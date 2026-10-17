<?php

namespace App\Observers;

use App\Enums\CheckpointType;
use App\Models\TripCheckpoint;

class TripCheckpointObserver
{
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
