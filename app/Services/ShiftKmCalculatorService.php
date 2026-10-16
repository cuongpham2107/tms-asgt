<?php

namespace App\Services;

use App\Models\DriverShift;
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
        // Auto-sync missing swaps if driver swap checkpoints exist
        TripKmSplitService::syncMissingSwapsFromCheckpoints($trip);

        // Ensure checkpoints have shift_id resolved if possible
        foreach ($trip->checkpoints()->whereNull('shift_id')->whereNotNull('driver_id')->get() as $cp) {
            $shiftId = DriverShift::where('driver_id', $cp->driver_id)
                ->where('start_time', '<=', $cp->occurred_at ?? now())
                ->where(fn ($q) => $q->whereNull('end_time')->orWhere('end_time', '>=', $cp->occurred_at ?? now()))
                ->value('id')
                ?? DriverShift::where('driver_id', $cp->driver_id)->whereNull('end_time')->latest('start_time')->value('id')
                ?? DriverShift::where('driver_id', $cp->driver_id)->latest('start_time')->value('id');

            if ($shiftId) {
                $cp->update(['shift_id' => $shiftId]);
            }
        }

        // If trip->shift_id is null, resolve it for trip->driver_id
        if ($trip->shift_id === null && $trip->driver_id) {
            $tripShiftId = DriverShift::where('driver_id', $trip->driver_id)
                ->whereNull('end_time')
                ->latest('start_time')
                ->value('id')
                ?? DriverShift::where('driver_id', $trip->driver_id)->latest('start_time')->value('id');

            if ($tripShiftId) {
                $trip->update(['shift_id' => $tripShiftId]);
                $trip->refresh();
            }
        }

        $allDriverIds = collect([$trip->driver_id])
            ->merge($trip->driverSwaps()->pluck('from_driver_id'))
            ->merge($trip->driverSwaps()->pluck('to_driver_id'))
            ->merge($trip->checkpoints()->pluck('driver_id'))
            ->filter()
            ->unique();

        $resolvedShiftIds = collect();
        foreach ($allDriverIds as $dId) {
            $shifts = DriverShift::where('driver_id', $dId)
                ->where(function ($q) use ($trip) {
                    $q->whereNull('end_time')
                        ->orWhere(function ($sq) use ($trip) {
                            if ($trip->started_at) {
                                $sq->where('start_time', '<=', $trip->completed_at ?? now())
                                    ->where('end_time', '>=', $trip->started_at);
                            }
                        });
                })
                ->pluck('id');

            if ($shifts->isEmpty()) {
                $latest = DriverShift::where('driver_id', $dId)->latest('start_time')->value('id');
                if ($latest) {
                    $shifts = collect([$latest]);
                }
            }

            $resolvedShiftIds = $resolvedShiftIds->merge($shifts);
        }

        $shiftIds = collect([$trip->shift_id])
            ->merge($trip->driverSwaps()->pluck('from_shift_id'))
            ->merge($trip->driverSwaps()->pluck('to_shift_id'))
            ->merge($trip->checkpoints()->whereNotNull('shift_id')->pluck('shift_id'))
            ->merge($resolvedShiftIds)
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

        // Thêm các chuyến liên quan đến ca hoặc tài xế này:
        // 1. Swap ra khỏi ca (from_shift_id = $shift->id hoặc from_driver_id = $shift->driver_id)
        // 2. Swap vào ca (to_shift_id = $shift->id hoặc to_driver_id = $shift->driver_id)
        // 3. Chuyến do tài xế này chạy (driver_id = $shift->driver_id) nhưng shift_id chưa gán hoặc khác
        // 4. Chuyến có checkpoint thuộc ca hoặc tài xế này
        $additionalTripIds = Trip::query()
            ->whereNotNull('start_km')
            ->whereNotIn('id', $allTrips->pluck('id'))
            ->where(function ($query) use ($shift) {
                $query->where('driver_id', $shift->driver_id)
                    ->orWhereHas('driverSwaps', fn ($q) => $q
                        ->where('from_shift_id', $shift->id)
                        ->orWhere('to_shift_id', $shift->id)
                        ->orWhere('from_driver_id', $shift->driver_id)
                        ->orWhere('to_driver_id', $shift->driver_id)
                    )
                    ->orWhereHas('checkpoints', fn ($q) => $q
                        ->where('shift_id', $shift->id)
                        ->orWhere('driver_id', $shift->driver_id)
                    );
            })
            ->pluck('id');

        if ($additionalTripIds->isNotEmpty()) {
            $additionalTrips = Trip::whereIn('id', $additionalTripIds)->get();
            $allTrips = $allTrips->concat($additionalTrips);
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

        // Không đảo lái → chuyến thuộc trọn ca này nếu tài xế khớp hoặc shift khớp
        if ($segments === []) {
            if ((int) $trip->shift_id === (int) $shift->id || (int) $trip->driver_id === (int) $shift->driver_id) {
                return [(float) ($trip->total_km ?? 0), (float) ($trip->total_km_loaded ?? 0)];
            }

            return [0.0, 0.0];
        }

        $loadedRanges = TripKmSplitService::loadedRanges($trip);

        $totalKm = 0.0;
        $loadedKm = 0.0;

        foreach ($segments as [$segStart, $segEnd, $segShiftId, $segDriverId]) {
            $matchesShift = $segShiftId !== null && (int) $segShiftId === (int) $shift->id;
            $matchesDriver = $segDriverId !== null && (int) $segDriverId === (int) $shift->driver_id;

            if (! $matchesShift && ! $matchesDriver) {
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
