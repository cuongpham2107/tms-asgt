<?php

namespace App\Services;

use App\Enums\CheckpointType;
use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TripKmSplitService
{
    /**
     * Chia chuyến đi thành các đoạn km theo các điểm bàn giao (mốc driver_swap có km).
     * Hỗ trợ đảo lái qua lại nhiều lần; self-swap (cùng tài xế + cùng ca) bị bỏ qua.
     *
     * @return list<array{0: float, 1: float, 2: ?int, 3: ?int}> [startKm, endKm, shiftId, driverId]
     */
    public static function segments(Trip $trip): array
    {
        $points = self::handoverPoints($trip);

        if ($points === []) {
            return [];
        }

        $startKm = (float) ($trip->start_km ?? 0);
        $tripEndKm = self::effectiveEndKm($trip, $startKm);

        $firstCheckpoint = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('driver_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();

        $activeDriverId = $points[0]['from_driver_id'] ?? $firstCheckpoint?->driver_id ?? $trip->driver_id;
        $activeShiftId = $points[0]['from_shift_id'] ?? $firstCheckpoint?->shift_id ?? $trip->shift_id;
        $prevKm = $startKm;
        $segments = [];

        foreach ($points as $point) {
            $segments[] = [$prevKm, $point['km'], $activeShiftId, $activeDriverId];
            $activeDriverId = $point['to_driver_id'];
            $activeShiftId = $point['to_shift_id'];
            $prevKm = $point['km'];
        }

        $segments[] = [$prevKm, $tripEndKm, $activeShiftId, $activeDriverId];

        return $segments;
    }

    /**
     * Các điểm bàn giao của chuyến, sắp theo giờ.
     *
     * @return list<array{km: float, at: Carbon, from_driver_id: ?int, to_driver_id: ?int, from_shift_id: ?int, to_shift_id: ?int}>
     */
    private static function handoverPoints(Trip $trip): array
    {
        $swaps = DriverSwap::where('trip_id', $trip->id)->orderBy('id')->get();
        $matched = self::matchUnlinkedSwaps($trip);

        $groups = self::swapCheckpointGroups($trip);

        $points = [];
        $usedSwapIds = [];

        foreach ($groups as $group) {
            $ids = $group->pluck('id')->all();
            $inGroup = $swaps->filter(fn (DriverSwap $s): bool => in_array($s->checkpoint_id ?? $matched[$s->id] ?? null, $ids, true));
            $swap = $inGroup->last();
            $firstSwap = $inGroup->first();
            $anchor = $swap ? $group->firstWhere('id', $swap->checkpoint_id ?? $matched[$swap->id]) : $group->first();
            $usedSwapIds = [...$usedSwapIds, ...$inGroup->pluck('id')->all()];

            $next = ($swap || ! $anchor->occurred_at) ? null : TripCheckpoint::where('trip_id', $trip->id)
                ->where('checkpoint_type', '!=', CheckpointType::DriverSwap)
                ->where('occurred_at', '>', $anchor->occurred_at)
                ->whereNotNull('driver_id')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->first();

            $points[] = [
                'km' => (float) $anchor->km_reading,
                'at' => $anchor->occurred_at ?? now(),
                'from_driver_id' => $firstSwap?->from_driver_id ?? $anchor->driver_id,
                'to_driver_id' => $swap ? $swap->to_driver_id : $next?->driver_id,
                'from_shift_id' => $anchor->shift_id ?? $firstSwap?->from_shift_id,
                'to_shift_id' => $swap ? $swap->to_shift_id : $next?->shift_id,
            ];
        }

        foreach ($swaps->whereNotIn('id', $usedSwapIds) as $swap) {
            $km = (float) ($swap->handover_km ?: HandoverKmResolver::resolve($trip, $swap));

            if ($km <= 0) {
                continue;
            }

            // driver_swaps.created_at do DB ghi (CURRENT_TIMESTAMP) là UTC, còn occurred_at của mốc theo giờ app.
            $points[] = [
                'km' => $km,
                'at' => $swap->created_at
                    ? Carbon::parse($swap->created_at->format('Y-m-d H:i:s'), 'UTC')->setTimezone(config('app.timezone'))
                    : now(),
                'from_driver_id' => $swap->from_driver_id,
                'to_driver_id' => $swap->to_driver_id,
                'from_shift_id' => $swap->from_shift_id,
                'to_shift_id' => $swap->to_shift_id,
            ];
        }

        $points = array_values(array_filter(
            $points,
            fn (array $p): bool => ! ($p['from_driver_id'] === $p['to_driver_id'] && $p['from_shift_id'] === $p['to_shift_id']),
        ));

        usort($points, fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['km']] <=> [$b['at']->getTimestamp(), $b['km']]);

        return $points;
    }

    /**
     * Mốc driver_swap có km, nhóm theo giờ (đến giây); mỗi nhóm là một điểm bàn giao.
     *
     * @return Collection<string, Collection<int, TripCheckpoint>>
     */
    private static function swapCheckpointGroups(Trip $trip): Collection
    {
        return TripCheckpoint::where('trip_id', $trip->id)
            ->where('checkpoint_type', CheckpointType::DriverSwap)
            ->whereNotNull('km_reading')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (TripCheckpoint $cp): string => $cp->occurred_at?->format('Y-m-d H:i:s') ?? '');
    }

    /**
     * Ghép swap chưa có checkpoint_id với nhóm mốc driver_swap chưa gắn, cùng km, theo thứ tự thời gian.
     *
     * @return array<int, int> driver_swap_id => checkpoint_id
     */
    public static function matchUnlinkedSwaps(Trip $trip): array
    {
        $linkedIds = DriverSwap::where('trip_id', $trip->id)->whereNotNull('checkpoint_id')->pluck('checkpoint_id')->all();

        $freeAnchors = self::swapCheckpointGroups($trip)
            ->reject(fn ($group): bool => $group->pluck('id')->intersect($linkedIds)->isNotEmpty())
            ->map(fn ($group): TripCheckpoint => $group->first())
            ->values();

        $matches = [];

        foreach (DriverSwap::where('trip_id', $trip->id)->whereNull('checkpoint_id')->orderBy('id')->get() as $swap) {
            $index = $freeAnchors->search(fn (TripCheckpoint $cp): bool => abs((float) $cp->km_reading - (float) $swap->handover_km) <= 0.05);

            if ($index === false) {
                continue;
            }

            $matches[$swap->id] = $freeAnchors[$index]->id;
            $freeAnchors->forget($index);
        }

        return $matches;
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
