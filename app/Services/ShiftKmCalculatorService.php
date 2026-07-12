<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\Order;
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

        $totalKm = 0;
        $totalLoaded = 0;

        foreach ($allTrips as $trip) {
            $tripTotalKm = (float) ($trip->total_km ?? 0);
            $tripLoadedKm = (float) ($trip->total_km_loaded ?? 0);

            // Chuyến đang chạy chưa có total_km → dùng km checkpoint mới nhất
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
