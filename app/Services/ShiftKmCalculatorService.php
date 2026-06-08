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
            $shiftStartedKm = TripCheckpoint::where('shift_id', $shift->id)
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'started')
                ->where('km_reading', '!=', null)
                ->value('km_reading');

            $shiftCompletedKm = TripCheckpoint::where('shift_id', $shift->id)
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'completed')
                ->where('km_reading', '!=', null)
                ->value('km_reading');

            if ($shiftStartedKm !== null && $shiftCompletedKm !== null) {
                if ($shiftCompletedKm > $shiftStartedKm) {
                    $totalLoadedKm += $shiftCompletedKm - $shiftStartedKm;
                }
            } elseif ($shiftStartedKm !== null) {
                $endKm = $shift->lastSegment()?->end_km;
                if ($endKm !== null && $endKm > $shiftStartedKm) {
                    $totalLoadedKm += $endKm - $shiftStartedKm;
                }
            } elseif ($shiftCompletedKm !== null) {
                $startKm = $shift->firstSegment()?->start_km;
                if ($startKm !== null && $shiftCompletedKm > $startKm) {
                    $totalLoadedKm += $shiftCompletedKm - $startKm;
                }
            }
        }

        $totalKm = $shift->shiftVehicles()
            ->whereNotNull('end_km')
            ->get()
            ->sum(fn ($sv) => (float) $sv->end_km - (float) $sv->start_km);

        $shift->total_km = $totalKm;
        $shift->total_km_loaded = $totalLoadedKm;
        $shift->total_km_empty = $shift->total_km - $totalLoadedKm;
        $shift->save();
    }
}
