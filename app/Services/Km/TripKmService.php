<?php

namespace App\Services\Km;

use App\Models\Trip;

/**
 * Điểm vào duy nhất để tính lại km sau khi chuyến thay đổi: km chuyến (tổng / có hàng / không hàng)
 * rồi km của ca chứa chuyến. Mọi luồng (mốc từ App, sửa chuyến, đảo lái, rời xe) gọi qua đây.
 */
class TripKmService
{
    public function __construct(
        private readonly TripKmCalculatorService $tripKm,
        private readonly ShiftKmCalculatorService $shiftKm,
    ) {}

    public function recalculate(Trip $trip, ?float $endKm = null): void
    {
        $this->tripKm->calculate($trip, endKm: $endKm);
        $trip->refresh();
        $this->shiftKm->calculateForTrip($trip);
    }
}
