<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\DriverSwap;
use App\Models\Order;
use App\Models\Trip;
use App\Models\TripCheckpoint;

class ShiftKmCalculatorService
{
    /**
     * Tính lại KM cho tất cả các ca liên quan đến chuyến đi (ca hiện tại, ca đảo lái, ca có checkpoint).
     */
    public function calculateForTrip(Trip $trip): void
    {
        $shiftIds = collect([$trip->shift_id])
            ->merge($trip->driverSwaps()->pluck('from_shift_id'))
            ->merge($trip->driverSwaps()->pluck('to_shift_id'))
            ->merge($trip->checkpoints()->whereNotNull('shift_id')->pluck('shift_id'))
            ->filter()
            ->unique();

        foreach ($shiftIds as $shiftId) {
            $shift = DriverShift::query()->find($shiftId);
            if ($shift instanceof DriverShift) {
                $this->calculate($shift);
            }
        }
    }

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

        $totalKm = 0.0;
        $totalLoaded = 0.0;

        foreach ($allTrips as $trip) {
            [$tripKm, $tripLoaded] = $this->calculateTripForShift($shift, $trip);

            $totalKm += $tripKm;
            $totalLoaded += $tripLoaded;
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

    /**
     * Tính km của ca trên một chuyến đi, cộng dồn tất cả các đoạn khi đảo lái qua lại nhiều lần.
     *
     * @return array{0: float, 1: float} [totalKm, loadedKm]
     */
    private function calculateTripForShift(DriverShift $shift, Trip $trip): array
    {
        $segments = TripKmSplitService::segments($trip);

        // Không đảo lái → chuyến thuộc trọn ca này, dùng trực tiếp km của chuyến
        if ($segments === []) {
            return [(float) ($trip->total_km ?? 0), (float) ($trip->total_km_loaded ?? 0)];
        }

        $loadedRanges = TripKmSplitService::loadedRanges($trip);

        $totalKm = 0.0;
        $loadedKm = 0.0;

        foreach ($segments as [$segStart, $segEnd, $segShiftId]) {
            if ((int) $segShiftId !== (int) $shift->id) {
                continue;
            }

            $totalKm += max(0.0, (float) $segEnd - (float) $segStart);

            foreach ($loadedRanges as [$loadStart, $loadEnd]) {
                $loadedKm += max(0.0, min((float) $segEnd, (float) $loadEnd) - max((float) $segStart, (float) $loadStart));
            }
        }

        return [$totalKm, $loadedKm];
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
