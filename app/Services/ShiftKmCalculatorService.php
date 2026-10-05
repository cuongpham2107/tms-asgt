<?php

namespace App\Services;

use App\Models\DriverShift;
use App\Models\TripDriverAssignment;
use App\Services\Gps\GpsDistanceService;

/**
 * Km ca của tài xế: tổng = GPS điện thoại của tài xế suốt ca (gồm chạy rỗng giữa các chuyến);
 * có hàng = tổng km có hàng các lượt lái trong ca; không hàng = phần còn lại.
 */
class ShiftKmCalculatorService
{
    public function __construct(private readonly GpsDistanceService $gps) {}

    public function calculate(DriverShift $shift): void
    {
        if ($shift->start_time === null || $shift->end_time === null) {
            return;
        }

        $total = $this->gps->distance(null, $shift->start_time, $shift->end_time, $shift->driver_id)->km;

        $loaded = (float) TripDriverAssignment::query()
            ->where('shift_id', $shift->id)
            ->where('driver_id', $shift->driver_id)
            ->sum('km_loaded');

        $assignmentTotal = (float) TripDriverAssignment::query()
            ->where('shift_id', $shift->id)
            ->where('driver_id', $shift->driver_id)
            ->sum('km');

        $total = max($total, $assignmentTotal, $loaded);

        $shift->total_km = round($total, 1);
        $shift->total_km_loaded = round($loaded, 1);
        $shift->total_km_empty = round(max(0, $total - $loaded), 1);
        $shift->km_calculated_at = now();
        $shift->save();
    }
}
