<?php

namespace App\Services\Km;

use App\Filament\Widgets\VehicleKmOverChartWidget;
use App\Models\Vehicle;
use App\Models\VehicleKmCycle;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Ghi km của một xe trong một kỳ KM over (12:00 ngày 23 → 12:00 ngày 23 tháng sau) vào vehicle_km_cycles.
 * Mỗi xe một dòng cho mỗi kỳ: ghi lại cùng kỳ thì cập nhật dòng cũ (bản lưu tạm được job chốt ngày 23 thay bằng số cuối).
 */
class VehicleKmCycleRecorder
{
    public const CYCLE_DAY = 23;

    public const CYCLE_HOUR = 12;

    /**
     * Đầu kỳ KM over chứa thời điểm cho trước: 12:00 ngày 23 gần nhất không sau thời điểm đó.
     */
    public static function cycleStartFor(CarbonInterface $at): Carbon
    {
        $start = Carbon::instance($at)->setDay(self::CYCLE_DAY)->setTime(self::CYCLE_HOUR, 0);

        return $at->lt($start) ? $start->subMonthNoOverflow() : $start;
    }

    /**
     * Ghi kỳ từ km đầu kỳ hiện có của xe (monthly_start_km) đến km cuối kỳ cho trước.
     * Bỏ qua xe chưa có km đầu kỳ, hoặc kỳ đã chốt xong (km cuối kỳ đã lưu đúng bằng km đầu kỳ hiện tại của xe, tức job đã chuyển sang kỳ mới).
     */
    public function record(Vehicle $vehicle, float $endKm, Carbon $cycleStart, Carbon $cycleEnd): bool
    {
        if ($vehicle->monthly_start_km === null) {
            return false;
        }

        $existing = VehicleKmCycle::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('cycle_start', $cycleStart)
            ->first();

        $startKm = (float) $vehicle->monthly_start_km;

        if ($existing !== null && (float) $existing->end_km === $startKm) {
            return false;
        }

        $kmRun = max(0.0, $endKm - $startKm);
        $tracksKmOver = $vehicle->tracksKmOver();
        $overKm = $tracksKmOver ? max(0.0, $kmRun - VehicleKmOverChartWidget::KM_LIMIT) : 0.0;

        ($existing ?? new VehicleKmCycle(['vehicle_id' => $vehicle->id]))->fill([
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'start_km' => $startKm,
            'end_km' => $endKm,
            'km_run' => $kmRun,
            'tracks_km_over' => $tracksKmOver,
            'km_limit' => VehicleKmOverChartWidget::KM_LIMIT,
            'over_km' => $overKm,
            'over_fee_per_km' => VehicleKmOverChartWidget::OVER_FEE_PER_KM,
            'over_fee' => (int) round($overKm * VehicleKmOverChartWidget::OVER_FEE_PER_KM),
            'vehicle_owner' => $vehicle->owner,
        ])->save();

        return true;
    }
}
