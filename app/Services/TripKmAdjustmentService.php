<?php

namespace App\Services;

use App\Enums\TripKmReportStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;

class TripKmAdjustmentService
{
    /**
     * Xử lý báo cáo sai km: điều chỉnh checkpoint/trip và tính lại toàn bộ chuỗi.
     *
     * @param  float  $correctedKm  Số km thực tế được admin xác nhận
     */
    public function resolveReport(TripKmReport $report, float $correctedKm, ?string $adminNote, int $adminId): void
    {
        $trip = $report->trip;
        $systemKm = (float) ($report->system_km ?? 0);
        $delta = $correctedKm - $systemKm;

        // 1. Tìm và điều chỉnh checkpoint có km_reading gần nhất với system_km
        $this->adjustCheckpoints($trip, $systemKm, $correctedKm);

        // 2. Cập nhật start_km / end_km của trip nếu cần
        $this->adjustTripBoundary($trip, $systemKm, $correctedKm);

        // 3. Tính lại km chuyến
        app(TripKmCalculatorService::class)->calculate($trip);
        $trip->refresh();

        // 4. Tính lại tất cả ca liên quan
        $this->recalculateAllShifts($trip);

        // 5. Đồng bộ vehicle.current_mileage an toàn
        $this->syncVehicleMileage($trip);

        // 6. Đánh dấu report đã xử lý
        $report->update([
            'status' => TripKmReportStatus::Resolved,
            'admin_note' => $adminNote,
            'resolved_by' => $adminId,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Từ chối báo cáo sai km.
     */
    public function rejectReport(TripKmReport $report, ?string $adminNote, int $adminId): void
    {
        $report->update([
            'status' => TripKmReportStatus::Rejected,
            'admin_note' => $adminNote,
            'resolved_by' => $adminId,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Điều chỉnh checkpoint có km_reading khớp với system_km.
     */
    private function adjustCheckpoints(Trip $trip, float $systemKm, float $correctedKm): void
    {
        if ($systemKm <= 0) {
            return;
        }

        // Tìm checkpoint gần nhất với system_km (tolerance ±0.5)
        $checkpoint = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('km_reading')
            ->orderByRaw('ABS(km_reading - ?) ASC', [$systemKm])
            ->first();

        if ($checkpoint !== null && abs((float) $checkpoint->km_reading - $systemKm) < 1.0) {
            $checkpoint->update(['km_reading' => $correctedKm]);
        }
    }

    /**
     * Cập nhật start_km / end_km nếu mốc bị sửa là mốc đầu/cuối.
     */
    private function adjustTripBoundary(Trip $trip, float $systemKm, float $correctedKm): void
    {
        if ($systemKm <= 0) {
            return;
        }

        if (abs((float) $trip->start_km - $systemKm) < 1.0) {
            $trip->update(['start_km' => $correctedKm]);
        }

        if ($trip->end_km !== null && abs((float) $trip->end_km - $systemKm) < 1.0) {
            $trip->update(['end_km' => $correctedKm]);
        }
    }

    /**
     * Tính lại km cho tất cả ca liên quan đến chuyến (ca chính + đảo lái).
     */
    private function recalculateAllShifts(Trip $trip): void
    {
        $shiftIds = collect([$trip->shift_id])
            ->merge($trip->driverSwaps()->pluck('from_shift_id'))
            ->merge($trip->driverSwaps()->pluck('to_shift_id'))
            ->filter()
            ->unique();

        foreach ($shiftIds as $shiftId) {
            $shift = DriverShift::find($shiftId);
            if ($shift !== null) {
                app(ShiftKmCalculatorService::class)->calculate($shift);
            }
        }
    }

    /**
     * Đồng bộ vehicle.current_mileage = max km của tất cả checkpoint gần nhất.
     */
    private function syncVehicleMileage(Trip $trip): void
    {
        $vehicle = $trip->vehicle;
        if ($vehicle === null) {
            return;
        }

        // Lấy end_km cao nhất từ các trip đã xong của xe
        $maxTripEndKm = Trip::where('vehicle_id', $vehicle->id)
            ->whereNotNull('end_km')
            ->max('end_km');

        // Lấy max km_reading từ tất cả checkpoints của xe (qua trips)
        $maxCheckpointKm = TripCheckpoint::whereHas('trip', fn ($q) => $q->where('vehicle_id', $vehicle->id))
            ->whereNotNull('km_reading')
            ->max('km_reading');

        $safeMileage = max(
            (float) ($maxTripEndKm ?? 0),
            (float) ($maxCheckpointKm ?? 0),
        );

        if ($safeMileage > 0) {
            $vehicle->update(['current_mileage' => $safeMileage]);
        }
    }
}
