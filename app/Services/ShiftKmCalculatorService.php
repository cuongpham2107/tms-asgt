<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\TripCheckpoint;

class ShiftKmCalculatorService
{
    public function calculate(DriverShift $shift): void
    {
        if ($shift->start_km === null || $shift->end_km === null) {
            return;
        }

        $orders = TripCheckpoint::where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', ['arrived_pickup', 'left_pickup', 'completed'])
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('order_id');

        $totalLoadedKm = 0;

        foreach ($orders as $orderId => $points) {
            $completed = $points->firstWhere('checkpoint_type', 'completed');
            $leftPickup = $points->firstWhere('checkpoint_type', 'left_pickup');
            $arrivedPickup = $points->firstWhere('checkpoint_type', 'arrived_pickup');

            $loadedStartKm = $leftPickup?->km_reading ?? $arrivedPickup?->km_reading;

            if ($loadedStartKm !== null) {
                if ($completed?->km_reading !== null) {
                    $totalLoadedKm += $completed->km_reading - $loadedStartKm;
                } else {
                    $totalLoadedKm += $shift->end_km - $loadedStartKm;
                }
            } elseif ($completed?->km_reading !== null) {
                $hasPriorLeftPickup = TripCheckpoint::where('order_id', $orderId)
                    ->where('checkpoint_type', 'left_pickup')
                    ->where('km_reading', '!=', null)
                    ->where('shift_id', '!=', $shift->id)
                    ->exists();

                if ($hasPriorLeftPickup) {
                    $totalLoadedKm += $completed->km_reading - $shift->start_km;
                }
            }
        }

        $shift->total_km = $shift->end_km - $shift->start_km;
        $shift->total_km_loaded = $totalLoadedKm;
        $shift->total_km_empty = $shift->total_km - $totalLoadedKm;
        $shift->save();
    }
}
