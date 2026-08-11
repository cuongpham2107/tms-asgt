<?php

namespace App\Services;

use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;

class HandoverKmResolver
{
    /**
     * Lấy km handover của một swap, fallback về driver_swap checkpoint của shift bàn giao.
     */
    public static function resolve(Trip $trip, DriverSwap $swap, bool $useTripFallback = false): float
    {
        $handoverKm = (float) ($swap->handover_km ?? 0);

        if ($handoverKm <= 0 && $swap->from_shift_id !== null) {
            $handoverKm = (float) TripCheckpoint::where('trip_id', $trip->id)
                ->where('checkpoint_type', 'driver_swap')
                ->where('shift_id', $swap->from_shift_id)
                ->whereNotNull('km_reading')
                ->orderByDesc('occurred_at')
                ->value('km_reading') ?? 0;
        }

        if ($handoverKm <= 0 && $useTripFallback) {
            $handoverKm = (float) TripCheckpoint::where('trip_id', $trip->id)
                ->where('checkpoint_type', 'driver_swap')
                ->whereNotNull('km_reading')
                ->orderByDesc('occurred_at')
                ->value('km_reading') ?? 0;
        }

        return $handoverKm;
    }
}
