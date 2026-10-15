<?php

namespace App\Services;

use App\Enums\TripKmReportStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use Illuminate\Support\Facades\DB;

class TripKmAdjustmentService
{
    /**
     * Xác định checkpoint mục tiêu từ ID chỉ định hoặc từ report.
     */
    public function resolveTargetCheckpoint(Trip $trip, ?int $targetCheckpointId = null, ?TripKmReport $report = null): ?TripCheckpoint
    {
        if ($targetCheckpointId) {
            $cp = TripCheckpoint::where('trip_id', $trip->id)->where('id', $targetCheckpointId)->first();
            if ($cp) {
                return $cp;
            }
        }

        if ($report && $report->checkpoint_id) {
            $cp = $report->checkpoint ?? TripCheckpoint::where('trip_id', $trip->id)->where('id', $report->checkpoint_id)->first();
            if ($cp) {
                return $cp;
            }
        }

        return TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('km_reading')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Tính toán giới hạn Km cho một checkpoint mục tiêu trong chuyến đi.
     *
     * @return array{
     *     target_checkpoint: ?TripCheckpoint,
     *     target_label: string,
     *     min_km: ?float,
     *     min_label: ?string,
     *     max_km: ?float,
     *     max_label: ?string,
     *     prev_checkpoint: ?TripCheckpoint,
     *     next_checkpoint: ?TripCheckpoint
     * }
     */
    public function getKmBoundaries(Trip $trip, ?int $targetCheckpointId = null, ?TripKmReport $report = null): array
    {
        $targetCp = $this->resolveTargetCheckpoint($trip, $targetCheckpointId, $report);

        if (! $targetCp) {
            $startKm = $trip->start_km !== null ? (float) $trip->start_km : null;
            $endKm = $trip->end_km !== null ? (float) $trip->end_km : null;

            return [
                'target_checkpoint' => null,
                'target_label' => 'Bắt đầu chuyến (start_km)',
                'min_km' => 0.0,
                'min_label' => 'Số dương (> 0)',
                'max_km' => $endKm,
                'max_label' => $endKm ? "Km kết thúc chuyến ({$endKm} km)" : null,
                'prev_checkpoint' => null,
                'next_checkpoint' => null,
            ];
        }

        $allCps = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('km_reading')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $prevCp = null;
        $nextCp = null;
        $oldKm = (float) $targetCp->km_reading;

        foreach ($allCps as $cp) {
            // Checkpoint cùng thời điểm và cùng số km với targetCp (đồng hành) thì bỏ qua khi xét trước/sau
            $isSameOrCompanion = ($cp->id === $targetCp->id)
                || ($cp->occurred_at == $targetCp->occurred_at && (float) $cp->km_reading === $oldKm);

            if ($isSameOrCompanion) {
                continue;
            }

            if ($cp->occurred_at < $targetCp->occurred_at || ($cp->occurred_at == $targetCp->occurred_at && $cp->id < $targetCp->id)) {
                $prevCp = $cp;
            } elseif ($nextCp === null && ($cp->occurred_at > $targetCp->occurred_at || ($cp->occurred_at == $targetCp->occurred_at && $cp->id > $targetCp->id))) {
                $nextCp = $cp;
            }
        }

        $minKm = $prevCp ? (float) $prevCp->km_reading : null;
        $maxKm = $nextCp ? (float) $nextCp->km_reading : null;

        $targetTypeLabel = $targetCp->checkpoint_type?->getLabel() ?? $targetCp->checkpoint_type?->value ?? 'Mốc';
        $targetTime = $targetCp->occurred_at ? $targetCp->occurred_at->format('H:i d/m') : '';
        $targetLabel = "[{$targetTime}] {$targetTypeLabel} ({$oldKm} km)";

        $prevTypeLabel = $prevCp ? ($prevCp->checkpoint_type?->getLabel() ?? $prevCp->checkpoint_type?->value ?? 'Mốc trước') : '';
        $minLabel = $prevCp ? "Mốc trước: [{$prevCp->occurred_at?->format('H:i')}] {$prevTypeLabel} ({$minKm} km)" : null;

        $nextTypeLabel = $nextCp ? ($nextCp->checkpoint_type?->getLabel() ?? $nextCp->checkpoint_type?->value ?? 'Mốc sau') : '';
        $maxLabel = $nextCp ? "Mốc sau: [{$nextCp->occurred_at?->format('H:i')}] {$nextTypeLabel} ({$maxKm} km)" : null;

        return [
            'target_checkpoint' => $targetCp,
            'target_label' => $targetLabel,
            'min_km' => $minKm,
            'min_label' => $minLabel,
            'max_km' => $maxKm,
            'max_label' => $maxLabel,
            'prev_checkpoint' => $prevCp,
            'next_checkpoint' => $nextCp,
        ];
    }

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
        DB::transaction(function () use ($report, $correctedKm, $targetCheckpointId, $adminNote, $adminId) {
            $trip = $report->trip;

            // 1. Xác định checkpoint mục tiêu (từ Admin chọn, hoặc lưu sẵn trong report, hoặc mới nhất)
            $targetCp = $this->resolveTargetCheckpoint($trip, $targetCheckpointId, $report);

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
            } else {
                if ($trip->start_km !== null) {
                    $trip->update(['start_km' => $correctedKm]);
                }
                if ($trip->vehicle) {
                    $trip->vehicle->update(['current_mileage' => $correctedKm]);
                }
            }

            // 2. Tính lại km chuyến
            app(TripKmCalculatorService::class)->calculate($trip);
            $trip->refresh();

            // 3. Tính lại tất cả ca liên quan
            $this->recalculateAllShifts($trip);

            // 4. Đồng bộ số km xe an toàn
            $this->syncVehicleMileage($trip);

            // 5. Mở lại chuyến xe (unpause)
            $trip->update([
                'is_paused' => false,
                'paused_reason' => null,
            ]);

            // 6. Đánh dấu report đã xử lý
            $report->update([
                'status' => TripKmReportStatus::Resolved,
                'checkpoint_id' => $targetCp?->id ?? $report->checkpoint_id,
                'admin_note' => $adminNote,
                'resolved_by' => $adminId,
                'resolved_at' => now(),
            ]);
        });
    }

    /**
     * Từ chối báo cáo sai km.
     */
    public function rejectReport(TripKmReport $report, ?string $adminNote, ?int $adminId = null): void
    {
        DB::transaction(function () use ($report, $adminNote, $adminId) {
            $report->update([
                'status' => TripKmReportStatus::Rejected,
                'admin_note' => $adminNote,
                'resolved_by' => $adminId,
                'resolved_at' => now(),
            ]);

            $report->trip?->update([
                'is_paused' => false,
                'paused_reason' => null,
            ]);
        });
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

            if ($trip->shift && $trip->shift->trips()->orderBy('id')->first()?->id === $trip->id) {
                $trip->shift->update(['start_km' => $correctedKm]);
            }
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
