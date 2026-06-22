<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\TripCheckpoint;

class ShiftKmCalculatorService
{
    public function calculate(DriverShift $shift): void
    {
        $shift->refresh();

        if ($shift->start_km === null || $shift->end_km === null) {
            $shift->total_km = null;
            $shift->total_km_loaded = null;
            $shift->total_km_empty = null;
            $shift->save();

            return;
        }

        $startKm = (float) $shift->start_km;
        $endKm = (float) $shift->end_km;
        $totalKm = $endKm - $startKm;

        if ($totalKm <= 0) {
            $shift->total_km = max(0, $totalKm);
            $shift->total_km_loaded = 0;
            $shift->total_km_empty = max(0, $totalKm);
            $shift->save();

            return;
        }

        $events = TripCheckpoint::where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', ['arrived_pickup', 'completed'])
            ->whereNotNull('order_id')
            ->whereNotNull('km_reading')
            ->orderBy('km_reading')
            ->get(['checkpoint_type', 'order_id', 'km_reading']);

        $arrivedOrderIds = $events->where('checkpoint_type', 'arrived_pickup')->pluck('order_id');
        $preloadedIds = $events->where('checkpoint_type', 'completed')
            ->pluck('order_id')
            ->diff($arrivedOrderIds);

        $activeOrderIds = collect($preloadedIds);
        $totalLoadedKm = 0;
        $prevKm = $startKm;

        foreach ($events as $event) {
            $eventKm = max((float) $event->km_reading, $prevKm);
            $typeStr = $event->getRawOriginal('checkpoint_type');

            if ($activeOrderIds->isNotEmpty() && $eventKm > $prevKm) {
                $totalLoadedKm += $eventKm - $prevKm;
            }

            if ($typeStr === 'arrived_pickup') {
                if (! $activeOrderIds->contains($event->order_id)) {
                    $activeOrderIds->push($event->order_id);
                }
            } else {
                $activeOrderIds = $activeOrderIds->filter(fn ($id) => $id !== $event->order_id);
            }

            $prevKm = $eventKm;
        }

        if ($activeOrderIds->isNotEmpty() && $endKm > $prevKm) {
            $totalLoadedKm += $endKm - $prevKm;
        }

        $shift->total_km = $totalKm;
        $shift->total_km_loaded = $totalLoadedKm;
        $shift->total_km_empty = max(0, $totalKm - $totalLoadedKm);
        $shift->save();
    }
}
