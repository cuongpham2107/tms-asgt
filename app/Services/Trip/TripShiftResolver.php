<?php

namespace App\Services\Trip;

use App\Models\DriverShift;
use App\Models\Trip;

class TripShiftResolver
{
    public function resolveForTrip(Trip $trip): void
    {
        if ($trip->shift_id !== null) {
            return;
        }

        $activeShift = DriverShift::where('driver_id', $trip->driver_id)
            ->whereNull('end_time')
            ->first();

        if ($activeShift !== null) {
            $trip->shift_id = $activeShift->id;
            $trip->save();
        }
    }
}
