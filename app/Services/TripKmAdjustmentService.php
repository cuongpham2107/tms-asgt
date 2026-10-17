<?php

namespace App\Services;

use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use Carbon\Carbon;
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
            $freshReport = TripKmReport::where('id', $report->id)->lockForUpdate()->first();
            if (! $freshReport || $freshReport->status !== TripKmReportStatus::Pending) {
                return;
            }

            $trip = $freshReport->trip;

            // 1. Xác định checkpoint mục tiêu (từ Admin chọn, hoặc lưu sẵn trong report, hoặc mới nhất)
            $targetCp = $this->resolveTargetCheckpoint($trip, $targetCheckpointId, $freshReport);

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
            }

            // 2. Tính lại km chuyến
            app(TripKmCalculatorService::class)->calculate($trip);
            $trip->refresh();

            // 3. Tính lại tất cả ca liên quan
            $this->recalculateAllShifts($trip);

            // 4. Đồng bộ số km xe an toàn
            $this->syncVehicleMileageIfLatest($trip);

            // 5. Mở lại chuyến xe (unpause)
            $trip->update([
                'is_paused' => false,
                'paused_reason' => null,
            ]);

            // 6. Đánh dấu report đã xử lý
            $freshReport->update([
                'status' => TripKmReportStatus::Resolved,
                'checkpoint_id' => $targetCp?->id ?? $freshReport->checkpoint_id,
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
            $freshReport = TripKmReport::where('id', $report->id)->lockForUpdate()->first();
            if (! $freshReport || $freshReport->status !== TripKmReportStatus::Pending) {
                return;
            }

            $freshReport->update([
                'status' => TripKmReportStatus::Rejected,
                'admin_note' => $adminNote,
                'resolved_by' => $adminId,
                'resolved_at' => now(),
            ]);

            $freshReport->trip?->update([
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
     * Tính lại km sau khi điều hành sửa chuyến: km đầu/cuối lấy từ mốc, rồi chuyến, ca, km xe (chỉ khi là chuyến mới nhất).
     */
    public function recalculateAfterEdit(Trip $trip): void
    {
        DB::transaction(function () use ($trip) {
            $this->syncBoundariesFromCheckpoints($trip);

            app(TripKmCalculatorService::class)->calculate($trip);
            $trip->refresh();

            app(ShiftKmCalculatorService::class)->calculateForTrip($trip);

            $this->syncVehicleMileageIfLatest($trip);
        });
    }

    /**
     * Dữ liệu đầu vào ảnh hưởng tới km chuyến/ca, đã chuẩn hóa (form lưu giờ theo phút, km dạng chuỗi).
     *
     * @return array{trip: array<string, mixed>, checkpoints: list<array<string, mixed>>}
     */
    public function kmSnapshot(Trip $trip): array
    {
        $km = fn ($value): ?float => $value === null ? null : round((float) $value, 1);
        $minute = fn ($value): ?string => $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i');
        $id = fn ($value): ?int => $value === null ? null : (int) $value;

        $row = Trip::query()->whereKey($trip->getKey())->first();

        return [
            'trip' => [
                'status' => $row?->getRawOriginal('status'),
                'start_km' => $km($row?->start_km),
                'end_km' => $km($row?->end_km),
                'driver_id' => $id($row?->driver_id),
                'shift_id' => $id($row?->shift_id),
                'started_at' => $minute($row?->started_at),
                'completed_at' => $minute($row?->completed_at),
            ],
            'checkpoints' => TripCheckpoint::query()
                ->where('trip_id', $trip->getKey())
                ->orderBy('id')
                ->get()
                ->map(fn (TripCheckpoint $cp): array => [
                    'id' => $cp->id,
                    'checkpoint_type' => $cp->getRawOriginal('checkpoint_type'),
                    'order_id' => $id($cp->order_id),
                    'driver_id' => $id($cp->driver_id),
                    'km_reading' => $km($cp->km_reading),
                    'occurred_at' => $minute($cp->occurred_at),
                ])
                ->all(),
        ];
    }

    /**
     * Điều hành đã sửa km trực tiếp → đóng các báo cáo sai km đang chờ của chuyến và bỏ tạm dừng.
     */
    public function closePendingReportsAfterEdit(Trip $trip, ?int $adminId): void
    {
        $pending = TripKmReport::where('trip_id', $trip->id)->where('status', TripKmReportStatus::Pending)->get();

        if ($pending->isEmpty()) {
            return;
        }

        $pending->each(fn (TripKmReport $report) => $report->update([
            'status' => TripKmReportStatus::Resolved,
            'admin_note' => 'Đã sửa km qua form sửa chuyến',
            'resolved_by' => $adminId,
            'resolved_at' => now(),
        ]));

        $trip->update(['is_paused' => false, 'paused_reason' => null]);
    }

    /**
     * Xe công ty: km bắt đầu = mốc sớm nhất, km kết thúc = mốc muộn nhất (khi chuyến đã có km kết thúc hoặc đã hoàn thành).
     */
    private function syncBoundariesFromCheckpoints(Trip $trip): void
    {
        $trip->loadMissing('vehicle');

        if ($trip->vehicle?->type === VehicleOwnerType::Rent) {
            return;
        }

        $kmCheckpoints = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('km_reading')
            ->where('km_reading', '>', 0);

        $first = (clone $kmCheckpoints)->orderBy('occurred_at')->orderBy('id')->first();
        $last = (clone $kmCheckpoints)->orderByDesc('occurred_at')->orderByDesc('id')->first();

        if ($first === null) {
            return;
        }

        $trip->start_km = $first->km_reading;

        if ($trip->end_km !== null || $trip->status === TripStatus::Completed) {
            $trip->end_km = $last->km_reading;
        }

        $trip->save();
    }

    /**
     * Km xe chỉ đi theo chuyến mới nhất của xe; sửa chuyến cũ không đụng km xe.
     */
    private function syncVehicleMileageIfLatest(Trip $trip): void
    {
        $vehicle = $trip->vehicle;

        if ($vehicle === null || $trip->started_at === null) {
            return;
        }

        $hasLaterTrip = Trip::where('vehicle_id', $vehicle->id)
            ->whereKeyNot($trip->id)
            ->whereNotNull('started_at')
            ->where('started_at', '>', $trip->started_at)
            ->exists();

        if ($hasLaterTrip) {
            return;
        }

        $finalKm = max(
            (float) ($trip->end_km ?? 0),
            (float) (TripCheckpoint::where('trip_id', $trip->id)->max('km_reading') ?? 0),
        );

        if ($finalKm > 0) {
            $vehicle->update(['current_mileage' => $finalKm]);
        }
    }
}
