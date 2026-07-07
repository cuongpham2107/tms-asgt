<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;

class TripKmCalculatorService
{
    public function calculate(Trip $trip, ?float $endKm = null): void
    {
        $trip->refresh();

        $startKm = (float) ($trip->start_km ?? 0);
        $endKmValue = $endKm ?? (float) ($trip->end_km ?? 0);

        if ($startKm <= 0) {
            $firstCheckpoint = TripCheckpoint::where('trip_id', $trip->id)
                ->whereNotNull('km_reading')
                ->orderBy('km_reading')
                ->first();

            if ($firstCheckpoint !== null && (float) $firstCheckpoint->km_reading > 0) {
                $startKm = (float) $firstCheckpoint->km_reading;
            }
        }

        if ($startKm <= 0 || $endKmValue <= 0) {
            return;
        }

        $totalKm = max(0, $endKmValue - $startKm);

        $events = TripCheckpoint::where('trip_id', $trip->id)
            ->whereIn('checkpoint_type', ['arrived_pickup', 'completed'])
            ->whereNotNull('order_id')
            ->whereNotNull('km_reading')
            ->orderBy('km_reading')
            ->get(['checkpoint_type', 'order_id', 'km_reading']);

        // Orders that have a 'completed' checkpoint WITHOUT a preceding 'arrived_pickup'
        // (preloaded: already on board when trip started)
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

        if ($activeOrderIds->isNotEmpty() && $endKmValue > $prevKm) {
            $totalLoadedKm += $endKmValue - $prevKm;
        }

        // Record per-order loaded_km
        $orderIds = $events->pluck('order_id')->unique();
        foreach ($orderIds as $orderId) {
            $pickup = $events
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'arrived_pickup')
                ->first();

            $complete = $events
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'completed')
                ->sortByDesc('km_reading')
                ->first();

            if ($pickup && $complete) {
                $orderLoadedKm = max(0, (float) $complete->km_reading - (float) $pickup->km_reading);
                Order::where('id', $orderId)->update(['loaded_km' => $orderLoadedKm]);
            }
        }

        $trip->total_km = $totalKm;
        $trip->total_km_loaded = $totalLoadedKm;
        $trip->total_km_empty = max(0, $totalKm - $totalLoadedKm);
        $trip->end_km = $endKm ?? $trip->end_km;
        $trip->save();
    }
}
