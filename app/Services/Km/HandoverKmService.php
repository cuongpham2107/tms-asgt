<?php

namespace App\Services\Km;

use App\Models\DriverSwap;
use App\Models\Trip;
use App\Models\TripCheckpoint;

class HandoverKmService
{
    /**
     * Lấy km handover của một swap, fallback về driver_swap checkpoint của shift bàn giao.
     */
    public static function resolve(Trip $trip, DriverSwap $swap, bool $useTripFallback = false, ?TripKmData $data = null): float
    {
        $handoverKm = (float) ($swap->handover_km ?? 0);

        if ($handoverKm <= 0 && $swap->from_shift_id !== null) {
            // Lấy tất cả driver_swap checkpoint của shift này, sắp xếp theo thời gian.
            // Khi có nhiều swap từ cùng 1 shift, mỗi swap tương ứng với 1 checkpoint
            // theo đúng thứ tự thời gian. Dùng ordinal position để match chính xác.
            $data ??= TripKmData::forTrip($trip);
            $shiftCheckpoints = $data->swapKmReadingsForShift((int) $swap->from_shift_id);

            $swapIndex = $data->swaps
                ->filter(fn (DriverSwap $other): bool => (int) $other->from_shift_id === (int) $swap->from_shift_id && $other->id < $swap->id)
                ->count();

            if (isset($shiftCheckpoints[$swapIndex])) {
                $handoverKm = (float) $shiftCheckpoints[$swapIndex];
            }
        }

        if ($handoverKm <= 0 && $useTripFallback) {
            $handoverKm = (float) TripCheckpoint::where('trip_id', $trip->id)
                ->where('checkpoint_type', 'driver_swap')
                ->whereNotNull('km_reading')
                ->orderByDesc('occurred_at')
                ->value('km_reading') ?? 0;
        }

        return $handoverKm;
    }
}
