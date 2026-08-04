<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;

class ShiftKmCalculatorService
{
    public function calculate(DriverShift $shift): void
    {
        $shift->refresh();

        if ($shift->start_km === null) {
            $shift->total_km = null;
            $shift->total_km_loaded = null;
            $shift->total_km_empty = null;
            $shift->save();

            return;
        }

        // Tổng hợp từ trip totals — gồm cả chuyến đang chạy (chưa có end_km)
        $allTrips = $shift->trips()->whereNotNull('start_km')->get();

        // Thêm các chuyến đã swap ra khỏi ca này (from_shift_id = shift hiện tại)
        // Chỉ tính nếu trip có ít nhất 1 checkpoint thuộc shift này
        $swappedOutTripIds = DriverSwap::where('from_shift_id', $shift->id)
            ->whereExists(fn ($q) => $q
                ->selectRaw(1)
                ->from('trip_checkpoints')
                ->whereRaw('trip_checkpoints.trip_id = driver_swaps.trip_id')
                ->where('trip_checkpoints.shift_id', $shift->id)
            )
            ->pluck('trip_id');

        if ($swappedOutTripIds->isNotEmpty()) {
            $swappedOutTrips = Trip::whereIn('id', $swappedOutTripIds)
                ->whereNotNull('start_km')
                ->whereNotIn('id', $allTrips->pluck('id'))
                ->get();

            $allTrips = $allTrips->concat($swappedOutTrips);
        }

        $totalKm = 0;
        $totalLoaded = 0;

        foreach ($allTrips as $trip) {
            // Determine handover KM from a driver_swap checkpoint on this trip (if any)
            $driverSwapCp = TripCheckpoint::where('trip_id', $trip->id)
                ->where('checkpoint_type', 'driver_swap')
                ->whereNotNull('km_reading')
                ->orderByDesc('occurred_at')
                ->first();

            $tripHandoverKm = (float) ($driverSwapCp?->km_reading ?? 0);

            // ——— attempt 1: swapped-IN explicit (DriverSwap có to_shift_id) ———
            // Bỏ qua swap vô nghĩa (self-swap: cùng tài xế + cùng ca)
            $swap = DriverSwap::where('trip_id', $trip->id)
                ->where('to_shift_id', $shift->id)
                ->where(function ($q) {
                    $q->whereColumn('from_shift_id', '!=', 'to_shift_id')
                        ->orWhereColumn('from_driver_id', '!=', 'to_driver_id');
                })
                ->first();

            if ($swap) {
                $handoverKm = (float) ($swap->handover_km ?? 0);
                if ($handoverKm <= 0) {
                    $handoverKm = $tripHandoverKm;
                }

                $shiftCheckpoints = $trip->checkpoints()
                    ->reorder()
                    ->where('shift_id', $shift->id)
                    ->whereNotNull('km_reading')
                    ->orderByDesc('occurred_at')
                    ->get();

                $latestKm = $shiftCheckpoints->first()?->km_reading;
                $tripTotalKm = $latestKm !== null ? max(0, (float) $latestKm - $handoverKm) : 0;

                $completedKm = $shiftCheckpoints
                    ->where('checkpoint_type', 'completed')
                    ->sortByDesc('occurred_at')
                    ->first()?->km_reading;

                $tripLoadedKm = $completedKm !== null ? max(0, (float) $completedKm - $handoverKm) : 0;
                if ($completedKm === null) {
                    $wasLoadedBefore = TripCheckpoint::where('trip_id', $trip->id)
                        ->where('checkpoint_type', 'arrived_pickup')
                        ->where('km_reading', '<=', $handoverKm)
                        ->exists();
                    $tripLoadedKm = $wasLoadedBefore ? $tripTotalKm : 0;
                }

                $totalKm += max(0, $tripTotalKm);
                $totalLoaded += $tripLoadedKm;

                continue;
            }

            $hasShiftCheckpoints = TripCheckpoint::where('trip_id', $trip->id)
                ->where('shift_id', $shift->id)
                ->exists();

            // ——— attempt 2: swapped-OUT (trip bị swap ra khỏi ca này) ———
            // Bỏ qua self-swap (cùng tài xế + cùng ca)
            $swapOut = DriverSwap::where('trip_id', $trip->id)
                ->where('from_shift_id', $shift->id)
                ->where(function ($q) {
                    $q->whereColumn('from_shift_id', '!=', 'to_shift_id')
                        ->orWhereColumn('from_driver_id', '!=', 'to_driver_id');
                })
                ->first();

            if ($swapOut && $hasShiftCheckpoints) {
                $handoverKm = (float) ($swapOut->handover_km ?? 0);
                if ($handoverKm <= 0) {
                    $handoverKm = $tripHandoverKm;
                }

                $tripTotalKm = $handoverKm > 0 && $trip->start_km > 0
                    ? max(0, $handoverKm - (float) $trip->start_km)
                    : 0;

                // Swap ra: không có completed checkpoint trong ca này
                $tripLoadedKm = 0;

                $totalKm += max(0, $tripTotalKm);
                $totalLoaded += $tripLoadedKm;

                continue;
            }

            // ——— attempt 3: swapped-IN implicit (to_shift_id = null) ———
            // Chỉ tính swapped-IN nếu shift này có checkpoint SAU driver_swap
            // (tài xế nhận chuyến sau điểm bàn giao). Nếu driver_swap là checkpoint
            // cuối → tài xế này swap RA (bàn giao cho người khác), không phải swapped-IN.
            $hasAfterSwap = $driverSwapCp && TripCheckpoint::where('trip_id', $trip->id)
                ->where('shift_id', $shift->id)
                ->where('occurred_at', '>', $driverSwapCp->occurred_at)
                ->exists();

            if ($driverSwapCp && $hasAfterSwap && $trip->shift_id == $shift->id && $driverSwapCp->shift_id !== $shift->id) {
                $shiftCheckpoints = $trip->checkpoints()
                    ->reorder()
                    ->where('shift_id', $shift->id)
                    ->whereNotNull('km_reading')
                    ->orderByDesc('occurred_at')
                    ->get();

                $latestKm = $shiftCheckpoints->first()?->km_reading;
                $tripTotalKm = $latestKm !== null ? max(0, (float) $latestKm - $tripHandoverKm) : 0;

                $completedKm = $shiftCheckpoints
                    ->where('checkpoint_type', 'completed')
                    ->sortByDesc('occurred_at')
                    ->first()?->km_reading;

                $tripLoadedKm = $completedKm !== null ? max(0, (float) $completedKm - $tripHandoverKm) : 0;
                if ($completedKm === null) {
                    $wasLoadedBefore = TripCheckpoint::where('trip_id', $trip->id)
                        ->where('checkpoint_type', 'arrived_pickup')
                        ->where('km_reading', '<=', $tripHandoverKm)
                        ->exists();
                    $tripLoadedKm = $wasLoadedBefore ? $tripTotalKm : 0;
                }

                $totalKm += max(0, $tripTotalKm);
                $totalLoaded += $tripLoadedKm;

                continue;
            }

            // ——— attempt 4: fully owned (không swap) ———
            $tripTotalKm = (float) ($trip->total_km ?? 0);
            $tripLoadedKm = (float) ($trip->total_km_loaded ?? 0);

            if ($tripTotalKm <= 0 && $trip->start_km > 0) {
                $latestKm = $trip->checkpoints()
                    ->reorder()
                    ->whereNotNull('km_reading')
                    ->orderByDesc('occurred_at')
                    ->value('km_reading');

                if ($latestKm !== null) {
                    $tripTotalKm = max(0, (float) $latestKm - (float) $trip->start_km);
                }
            }

            $totalKm += max(0, $tripTotalKm);
            $totalLoaded += $tripLoadedKm;
        }

        // Thêm phần km lang thang sau trip cuối (nếu có) — chỉ khi ca đã kết thúc
        $lastTrip = $allTrips->sortByDesc('completed_at')->first();
        $shiftEndKm = (float) ($shift->end_km ?? 0);
        if ($lastTrip && $shiftEndKm > 0) {
            $lastTripEnd = (float) ($lastTrip->end_km ?? 0);
            if ($shiftEndKm > $lastTripEnd) {
                $wanderingKm = $shiftEndKm - $lastTripEnd;
                $totalKm += $wanderingKm;
            }
        }

        $shift->total_km = $totalKm;
        $shift->total_km_loaded = $totalLoaded;
        $shift->total_km_empty = max(0, $totalKm - $totalLoaded);
        $shift->save();

        // Record per-order loaded_km
        $this->recordOrderLoadedKm($shift);
    }

    private function recordOrderLoadedKm(DriverShift $shift): void
    {
        $checkpoints = TripCheckpoint::where('shift_id', $shift->id)
            ->whereIn('checkpoint_type', ['arrived_pickup', 'completed'])
            ->whereNotNull('order_id')
            ->whereNotNull('km_reading')
            ->orderBy('km_reading')
            ->get(['checkpoint_type', 'order_id', 'km_reading']);

        $orderIds = $checkpoints->pluck('order_id')->unique();

        foreach ($orderIds as $orderId) {
            $pickup = $checkpoints
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'arrived_pickup')
                ->first();

            $complete = $checkpoints
                ->where('order_id', $orderId)
                ->where('checkpoint_type', 'completed')
                ->sortByDesc('km_reading')
                ->first();

            if ($pickup && $complete) {
                $loadedKm = max(0, (float) $complete->km_reading - (float) $pickup->km_reading);
                Order::where('id', $orderId)->update(['loaded_km' => $loadedKm]);
            }
        }
    }
}
