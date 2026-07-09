<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\Order;
use App\Models\TripCheckpoint;
use Illuminate\Support\Collection;

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

        // Fallback: if shift.start_km is 0 (e.g., not set during startShift
        // due to known bug), try to infer from trip or checkpoint data
        if ($startKm === 0.0 && $endKm > 0) {
            $firstCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
                ->whereNotIn('checkpoint_type', ['end', 'driver_swap'])
                ->whereNotNull('km_reading')
                ->orderBy('occurred_at')
                ->first();

            $firstTrip = $shift->trips()->orderBy('started_at')->first();

            // Prefer trip.start_km if the trip started on this shift
            // (trip's started_at is after shift's start_time)
            if ($firstTrip?->start_km !== null && (float) $firstTrip->start_km > 0) {
                $tripStartedHere = $shift->start_time !== null
                    && $firstTrip->started_at !== null
                    && $firstTrip->started_at->gte($shift->start_time);

                if ($tripStartedHere) {
                    $startKm = (float) $firstTrip->start_km;
                } elseif ($firstCheckpoint !== null && (float) $firstCheckpoint->km_reading > 0) {
                    // Trip was transferred from another shift → use first checkpoint km
                    $startKm = (float) $firstCheckpoint->km_reading;
                }
            } elseif ($firstCheckpoint !== null && (float) $firstCheckpoint->km_reading > 0) {
                $startKm = (float) $firstCheckpoint->km_reading;
            }
        }

        $totalKm = max(0, $endKm - $startKm);

        if ($totalKm <= 0) {
            $shift->total_km = $totalKm;
            $shift->total_km_loaded = 0;
            $shift->total_km_empty = $totalKm;
            $shift->save();

            return;
        }

        // Build vehicle-bound segments from 'end' checkpoints
        $allCheckpoints = TripCheckpoint::where('shift_id', $shift->id)
            ->orderBy('occurred_at')
            ->get();

        $segments = $this->buildSegments($allCheckpoints, $startKm, $endKm);

        $totalShiftKm = 0;
        $totalShiftLoaded = 0;

        foreach ($segments as $seg) {
            [$segKm, $segLoaded] = $this->calculateSegment(
                $seg['start_km'],
                $seg['end_km'],
                $seg['events'],
            );

            $totalShiftKm += $segKm;
            $totalShiftLoaded += $segLoaded;

            $this->recordOrderLoadedKm($seg['events']);
        }

        // Fallback: if segments produced nothing, run on whole shift
        if ($totalShiftKm <= 0 && $totalShiftLoaded <= 0) {
            $events = $allCheckpoints
                ->whereIn('checkpoint_type', ['arrived_pickup', 'completed'])
                ->whereNotNull('order_id')
                ->whereNotNull('km_reading')
                ->sortBy('km_reading')
                ->values()
                ->all();

            if (! empty($events)) {
                [$totalShiftKm, $totalShiftLoaded] = $this->calculateSegment($startKm, $endKm, $events);
                $this->recordOrderLoadedKm($events);
            } else {
                $totalShiftKm = $totalKm;
                $totalShiftLoaded = 0;
            }
        }

        $shift->total_km = max(0, $totalShiftKm);
        $shift->total_km_loaded = $totalShiftLoaded;
        $shift->total_km_empty = max(0, $totalShiftKm - $totalShiftLoaded);
        $shift->save();
    }

    /**
     * Build vehicle-bound segments delimited by 'end' checkpoints.
     * Each segment runs from the previous 'end' (or shift start) to the next 'end' (or shift end).
     *
     * @param  Collection  $checkpoints
     * @return array<int, array{start_km: float, end_km: float, events: array}>
     */
    private function buildSegments($checkpoints, float $globalStartKm, float $globalEndKm): array
    {
        // Find all 'end' checkpoints with km_reading
        $endCheckpoints = $checkpoints
            ->filter(fn ($cp) => $cp->checkpoint_type->value === 'end' && $cp->km_reading !== null)
            ->sortBy('occurred_at')
            ->values();

        if ($endCheckpoints->isEmpty()) {
            // No segments: treat entire shift as one segment
            $events = $checkpoints
                ->filter(fn ($cp) => in_array($cp->checkpoint_type->value, ['arrived_pickup', 'completed'], true)
                    && $cp->order_id !== null
                    && $cp->km_reading !== null)
                ->sortBy('km_reading')
                ->values()
                ->all();

            return [[
                'start_km' => $globalStartKm,
                'end_km' => $globalEndKm,
                'events' => $events,
            ]];
        }

        $segments = [];
        $prevEndKm = $globalStartKm;

        foreach ($endCheckpoints as $endCp) {
            $segEndKm = (float) $endCp->km_reading;

            // Ensure monotonic km (don't go backwards)
            $segStartKm = max($prevEndKm, $globalStartKm);
            $segEndKm = max($segEndKm, $segStartKm);

            // Find events that occurred between prevEnd and this end
            $events = $checkpoints
                ->filter(fn ($cp) => in_array($cp->checkpoint_type->value, ['arrived_pickup', 'completed'], true)
                    && $cp->order_id !== null
                    && $cp->km_reading !== null
                    && $cp->km_reading >= $segStartKm && (float) $cp->km_reading <= $segEndKm)
                ->sortBy('km_reading')
                ->values()
                ->all();

            $segments[] = [
                'start_km' => $segStartKm,
                'end_km' => $segEndKm,
                'events' => $events,
            ];

            $prevEndKm = $segEndKm;
        }

        // Final segment: from last 'end' to shift end
        if ($prevEndKm < $globalEndKm) {
            $events = $checkpoints
                ->filter(fn ($cp) => in_array($cp->checkpoint_type->value, ['arrived_pickup', 'completed'], true)
                    && $cp->order_id !== null
                    && $cp->km_reading !== null
                    && (float) $cp->km_reading >= $prevEndKm)
                ->sortBy('km_reading')
                ->values()
                ->all();

            if (! empty($events)) {
                $segments[] = [
                    'start_km' => $prevEndKm,
                    'end_km' => $globalEndKm,
                    'events' => $events,
                ];
            }
        }

        return $segments;
    }

    /**
     * Calculate loaded/empty km for a single vehicle segment.
     *
     * @param  array  $events  TripCheckpoint[] with type arrived_pickup|completed
     * @return array{0: float, 1: float} [totalKm, loadedKm]
     */
    private function calculateSegment(float $segStartKm, float $segEndKm, array $events): array
    {
        $totalKm = max(0, $segEndKm - $segStartKm);

        if (empty($events) || $totalKm <= 0) {
            return [$totalKm, 0];
        }

        // Sort events by km_reading for consistent loaded_km calculation
        usort($events, fn ($a, $b) => (float) $a->km_reading <=> (float) $b->km_reading);

        $arrivedOrderIds = [];
        foreach ($events as $e) {
            if ($e->checkpoint_type->value === 'arrived_pickup') {
                $arrivedOrderIds[] = $e->order_id;
            }
        }

        $preloadedIds = [];
        foreach ($events as $e) {
            if ($e->checkpoint_type->value === 'completed' && ! in_array($e->order_id, $arrivedOrderIds)) {
                $preloadedIds[] = $e->order_id;
            }
        }

        $activeOrderIds = $preloadedIds;
        $totalLoadedKm = 0;
        $prevKm = $segStartKm;

        foreach ($events as $event) {
            $eventKm = max((float) $event->km_reading, $prevKm);

            if (! empty($activeOrderIds) && $eventKm > $prevKm) {
                $totalLoadedKm += $eventKm - $prevKm;
            }

            if ($event->checkpoint_type->value === 'arrived_pickup') {
                if (! in_array($event->order_id, $activeOrderIds)) {
                    $activeOrderIds[] = $event->order_id;
                }
            } else {
                $activeOrderIds = array_values(array_filter($activeOrderIds, fn ($id) => (int) $id !== (int) $event->order_id));
            }

            $prevKm = $eventKm;
        }

        if (! empty($activeOrderIds) && $segEndKm > $prevKm) {
            $totalLoadedKm += $segEndKm - $prevKm;
        }

        return [$totalKm, $totalLoadedKm];
    }

    /**
     * Record per-order loaded_km from events in a segment.
     */
    private function recordOrderLoadedKm(array $events): void
    {
        $orderIds = array_unique(array_map(fn ($e) => (int) $e->order_id, $events));

        foreach ($orderIds as $orderId) {
            $pickup = null;
            $complete = null;

            foreach ($events as $e) {
                if ((int) $e->order_id !== $orderId) {
                    continue;
                }
                if ($e->checkpoint_type->value === 'arrived_pickup') {
                    $pickup = $e;
                }
                if ($e->checkpoint_type->value === 'completed') {
                    if ($complete === null || (float) $e->km_reading > (float) $complete->km_reading) {
                        $complete = $e;
                    }
                }
            }

            if ($pickup && $complete) {
                $orderLoadedKm = max(0, (float) $complete->km_reading - (float) $pickup->km_reading);
                Order::where('id', $orderId)->update(['loaded_km' => $orderLoadedKm]);
            }
        }
    }
}
