<?php

namespace App\Services;

use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;

class TripKmSplitService
{
    /**
     * Chia chuyến đi thành các đoạn km theo đúng thứ tự bàn giao (đảo lái).
     * Hỗ trợ đảo lái qua lại nhiều lần; self-swap (cùng tài xế + cùng ca) bị bỏ qua.
     *
     * @return list<array{0: float, 1: float, 2: ?int, 3: ?int}> [startKm, endKm, shiftId, driverId]
     */
    public static function segments(Trip $trip): array
    {
        $swaps = DriverSwap::where('trip_id', $trip->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($swaps->isEmpty()) {
            return [];
        }

        $startKm = (float) ($trip->start_km ?? 0);

        $tripEndKm = self::effectiveEndKm($trip, $startKm);

        // Ca/tài xế nắm chuyến lúc bắt đầu: của checkpoint đầu tiên
        $firstCheckpoint = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('shift_id')
            ->orderBy('occurred_at')
            ->first();

        $initialShiftId = $firstCheckpoint?->shift_id ?? $swaps->first()->from_shift_id ?? $trip->shift_id;
        $initialDriverId = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('driver_id')
            ->orderBy('occurred_at')
            ->value('driver_id')
            ?? $swaps->first()->from_driver_id
            ?? $trip->driver_id;

        $segments = [];
        $activeShiftId = $initialShiftId;
        $activeDriverId = $initialDriverId;
        $prevKm = $startKm;

        foreach ($swaps as $index => $swap) {
            // Bỏ qua self-swap vô nghĩa (cùng tài xế + cùng ca)
            if ($swap->from_driver_id === $swap->to_driver_id
                && $swap->from_shift_id === $swap->to_shift_id) {
                continue;
            }

            $handoverKm = HandoverKmResolver::resolve($trip, $swap, useTripFallback: true);
            if ($handoverKm <= 0) {
                continue;
            }

            $segments[] = [(float) $prevKm, $handoverKm, $activeShiftId, $activeDriverId];

            $activeShiftId = $swap->to_shift_id
                ?? $swaps->get($index + 1)?->from_shift_id
                ?? $trip->shift_id;
            $activeDriverId = $swap->to_driver_id
                ?? $swaps->get($index + 1)?->from_driver_id
                ?? $trip->driver_id;
            $prevKm = $handoverKm;
        }

        $segments[] = [(float) $prevKm, $tripEndKm, $activeShiftId, $activeDriverId];

        return $segments;
    }

    /**
     * Các đoạn km mà xe đang chở hàng (từ arrived_pickup đến completed của từng đơn).
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function loadedRanges(Trip $trip): array
    {
        $startKm = (float) ($trip->start_km ?? 0);

        $tripEndKm = self::effectiveEndKm($trip, $startKm);

        $events = TripCheckpoint::where('trip_id', $trip->id)
            ->whereIn('checkpoint_type', ['arrived_pickup', 'completed'])
            ->whereNotNull('order_id')
            ->whereNotNull('km_reading')
            ->orderBy('km_reading')
            ->get(['checkpoint_type', 'order_id', 'km_reading']);

        $arrivedOrderIds = $events->where('checkpoint_type', 'arrived_pickup')->pluck('order_id');
        $preloadedIds = $events->where('checkpoint_type', 'completed')
            ->pluck('order_id')
            ->diff($arrivedOrderIds);

        $activeOrderIds = collect($preloadedIds->values());

        $completedCounts = [];
        foreach ($events as $event) {
            if ($event->getRawOriginal('checkpoint_type') === 'completed') {
                $completedCounts[$event->order_id] = ($completedCounts[$event->order_id] ?? 0) + 1;
            }
        }
        $completedSeen = [];

        $ranges = [];
        $prevKm = $startKm;

        foreach ($events as $event) {
            $eventKm = max((float) $event->km_reading, $prevKm);
            $typeStr = $event->getRawOriginal('checkpoint_type');

            if ($activeOrderIds->isNotEmpty() && $eventKm > $prevKm) {
                $ranges[] = [(float) $prevKm, $eventKm];
            }

            if ($typeStr === 'arrived_pickup') {
                if (! $activeOrderIds->contains($event->order_id)) {
                    $activeOrderIds->push($event->order_id);
                }
            } else {
                $completedSeen[$event->order_id] = ($completedSeen[$event->order_id] ?? 0) + 1;
                if ($completedSeen[$event->order_id] >= ($completedCounts[$event->order_id] ?? 0)) {
                    $activeOrderIds = $activeOrderIds->filter(fn ($id) => $id !== $event->order_id);
                }
            }

            $prevKm = $eventKm;
        }

        if ($activeOrderIds->isNotEmpty() && $tripEndKm > $prevKm) {
            $ranges[] = [(float) $prevKm, $tripEndKm];
        }

        return $ranges;
    }

    /**
     * Tính km của chuyến thuộc về một tài xế cụ thể (bao gồm cả khi có đảo lái).
     *
     * @return array{total_km: float, total_km_loaded: float, total_km_empty: float}
     */
    public static function driverKm(Trip $trip, int $driverId): array
    {
        $segments = self::segments($trip);

        if (empty($segments)) {
            if ((int) $trip->driver_id !== $driverId || $trip->total_km === null) {
                return [
                    'total_km' => 0.0,
                    'total_km_loaded' => 0.0,
                    'total_km_empty' => 0.0,
                ];
            }

            $totalKm = (float) $trip->total_km;
            $loadedKm = (float) ($trip->total_km_loaded ?? 0);

            return [
                'total_km' => $totalKm,
                'total_km_loaded' => $loadedKm,
                'total_km_empty' => max(0.0, $totalKm - $loadedKm),
            ];
        }

        $loadedRanges = self::loadedRanges($trip);
        $totalKm = 0.0;
        $loadedKm = 0.0;

        foreach ($segments as [$segStart, $segEnd, $segShiftId, $segDriverId]) {
            if ((int) $segDriverId !== $driverId) {
                continue;
            }

            $totalKm += max(0.0, (float) $segEnd - (float) $segStart);

            foreach ($loadedRanges as [$loadStart, $loadEnd]) {
                $loadedKm += max(0.0, min((float) $segEnd, (float) $loadEnd) - max((float) $segStart, (float) $loadStart));
            }
        }

        return [
            'total_km' => $totalKm,
            'total_km_loaded' => $loadedKm,
            'total_km_empty' => max(0.0, $totalKm - $loadedKm),
        ];
    }

    /**
     * Km kết thúc hiệu dụng của chuyến. Lấy giá trị lớn nhất giữa end_km và
     * km_reading của checkpoint cuối để end_km lỗi thời (chưa gửi checkpoint
     * 'end') không làm mất km của tài xế cuối.
     */
    private static function effectiveEndKm(Trip $trip, float $startKm): float
    {
        $endKm = (float) ($trip->end_km ?? 0);
        $lastCheckpointKm = (float) (TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('km_reading')
            ->orderByDesc('occurred_at')
            ->value('km_reading') ?? 0);

        return max($startKm, $endKm, $lastCheckpointKm);
    }
}
