<?php

namespace App\Services;

use App\Enums\TripKmReportStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;

class TripKmAdjustmentService
{
    /**
     * Xử lý báo cáo sai km: điều chỉnh checkpoint chỉ định/gắn liền và tính lại toàn bộ chuỗi.
     *
     * @param  float  $correctedKm  Số km thực tế được admin xác nhận
     * @param  int|null  $targetCheckpointId  Checkpoint cụ thể được chọn điều chỉnh
     */
    public function resolveReport(
        TripKmReport $report,
        float $correctedKm,
        ?int $targetCheckpointId = null,
        ?string $adminNote = null,
        ?int $adminId = null
    ): void {
        $trip = $report->trip;

        // 1. Xác định checkpoint mục tiêu (từ Admin chọn, hoặc lưu sẵn trong report, hoặc mới nhất)
        $targetCp = null;
        if ($targetCheckpointId) {
            $targetCp = TripCheckpoint::where('trip_id', $trip->id)->find($targetCheckpointId);
        }
        if (! $targetCp && $report->checkpoint_id) {
            $targetCp = $report->checkpoint;
        }
        if (! $targetCp) {
            $targetCp = TripCheckpoint::where('trip_id', $trip->id)
                ->whereNotNull('km_reading')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->first();
        }

        if ($targetCp) {
            $oldKm = (float) $targetCp->km_reading;

            // Cập nhật checkpoint mục tiêu
            $targetCp->update(['km_reading' => $correctedKm]);

            // Cập nhật các checkpoint đồng hành cùng mốc thời gian và cùng số km cũ
            TripCheckpoint::where('trip_id', $trip->id)
                ->where('id', '!=', $targetCp->id)
                ->whereNotNull('km_reading')
                ->where('km_reading', $oldKm)
                ->where('occurred_at', $targetCp->occurred_at)
                ->update(['km_reading' => $correctedKm]);

            // Cập nhật start_km / end_km của trip nếu checkpoint nằm ở mốc biên
            $this->syncTripBoundary($trip, $targetCp, $correctedKm);
        }

        // 2. Tính lại km chuyến
        app(TripKmCalculatorService::class)->calculate($trip);
        $trip->refresh();

        // 3. Tính lại tất cả ca liên quan
        $this->recalculateAllShifts($trip);

        // 4. Đồng bộ vehicle.current_mileage an toàn
        $this->syncVehicleMileage($trip);

        // 5. Đánh dấu report đã xử lý
        $report->update([
            'status' => TripKmReportStatus::Resolved,
            'checkpoint_id' => $targetCp?->id ?? $report->checkpoint_id,
            'admin_note' => $adminNote,
            'resolved_by' => $adminId,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Từ chối báo cáo sai km.
     */
    public function rejectReport(TripKmReport $report, ?string $adminNote, ?int $adminId = null): void
    {
        $report->update([
            'status' => TripKmReportStatus::Rejected,
            'admin_note' => $adminNote,
            'resolved_by' => $adminId,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Cập nhật start_km / end_km của trip tương ứng với checkpoint được chỉnh sửa.
     */
    private function syncTripBoundary(Trip $trip, TripCheckpoint $checkpoint, float $correctedKm): void
    {
        $firstCp = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('km_reading')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->first();

        if ($firstCp && $firstCp->id === $checkpoint->id) {
            $trip->update(['start_km' => $correctedKm]);
        }

        $lastCp = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('km_reading')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($lastCp && $lastCp->id === $checkpoint->id) {
            if ($trip->end_km !== null) {
                $trip->update(['end_km' => $correctedKm]);
            }
        }
    }

    /**
     * Tính lại km cho tất cả ca liên quan đến chuyến (ca chính + đảo lái).
     */
    private function recalculateAllShifts(Trip $trip): void
    {
        app(ShiftKmCalculatorService::class)->calculateForTrip($trip);
    }

    /**
     * Đồng bộ vehicle.current_mileage = max km an toàn từ các trip/checkpoints.
     */
    private function syncVehicleMileage(Trip $trip): void
    {
        $vehicle = $trip->vehicle;
        if ($vehicle === null) {
            return;
        }

        $maxTripEndKm = Trip::where('vehicle_id', $vehicle->id)
            ->whereNotNull('end_km')
            ->max('end_km');

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
