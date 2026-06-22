<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\TripCheckpoint;

class ShiftKmCalculatorService
{
    public function calculate(DriverShift $shift): void
    {
        $shift->refresh();

        $orderIds = TripCheckpoint::where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', ['started', 'completed'])
            ->pluck('order_id')
            ->unique();

        $totalLoadedKm = 0;

        foreach ($orderIds as $orderId) {
            $arrivedKm = TripCheckpoint::where('shift_id', $shift->id)
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'arrived_pickup')
                ->whereNotNull('km_reading')
                ->value('km_reading');

            $completedKm = TripCheckpoint::where('shift_id', $shift->id)
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'completed')
                ->whereNotNull('km_reading')
                ->value('km_reading');

            if ($arrivedKm !== null && $completedKm !== null) {
                if ($completedKm > $arrivedKm) {
                    $totalLoadedKm += $completedKm - $arrivedKm;
                }
            } elseif ($arrivedKm !== null) {
                $endKm = $shift->lastSegment()?->end_km;
                if ($endKm !== null && $endKm > $arrivedKm) {
                    $totalLoadedKm += $endKm - $arrivedKm;
                }
            } elseif ($completedKm !== null) {
                $startKm = $shift->firstSegment()?->start_km;
                if ($startKm !== null && $completedKm > $startKm) {
                    $totalLoadedKm += $completedKm - $startKm;
                }
            }
        }

        $totalKm = 0;
        if ($shift->end_km !== null && $shift->start_km !== null) {
            $totalKm = (float) $shift->end_km - (float) $shift->start_km;
        }

        $shift->total_km = $totalKm;
        $shift->total_km_loaded = $totalLoadedKm;
        $shift->total_km_empty = max(0, $shift->total_km - $totalLoadedKm);
        $shift->save();
    }
}
