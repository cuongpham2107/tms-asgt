<?php

namespace App\Console\Commands;

use App\Enums\TripStatus;
use App\Filament\Widgets\VehicleKmOverChartWidget;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleKmCycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('vehicles:snapshot-monthly-km')]
#[Description('Chốt số Km đầu chu kỳ tháng lúc 12:00 ngày 23 hàng tháng (các chuyến đang chạy lấy start_km để tính sang tháng sau) và lưu km của kỳ vừa kết thúc')]
class SnapshotMonthlyVehicleKmCommand extends Command
{
    public function handle(): int
    {
        $this->info('Đang bắt đầu chốt Km bắt đầu chu kỳ tháng (12:00 ngày 23) cho các xe...');

        $cycleEnd = now()->startOfMinute();
        $cycleStart = $cycleEnd->copy()->subMonthNoOverflow();
        $newStartKm = $this->newStartKmByVehicle();

        $recorded = 0;
        $deferredCount = 0;
        $regularCount = 0;

        DB::transaction(function () use ($newStartKm, $cycleStart, $cycleEnd, &$recorded, &$deferredCount, &$regularCount): void {
            $vehicles = Vehicle::query()->whereIn('id', array_keys($newStartKm['all']))->get();

            foreach ($vehicles as $vehicle) {
                $endKm = $newStartKm['all'][$vehicle->id];

                // Lưu kỳ vừa kết thúc trước khi ghi đè km đầu kỳ (cùng công thức cột KM over trên bảng điều xe).
                if ($this->recordCycle($vehicle, $endKm, $cycleStart, $cycleEnd)) {
                    $recorded++;
                }

                // Cập nhật từng xe để Nhật ký hoạt động ghi lại km đầu chu kỳ cũ → mới.
                $vehicle->update(['monthly_start_km' => $endKm]);
                isset($newStartKm['deferred'][$vehicle->id]) ? $deferredCount++ : $regularCount++;
            }
        });

        $totalUpdated = $deferredCount + $regularCount;
        $message = "Đã hoàn thành chốt Km đầu chu kỳ tháng cho {$totalUpdated} xe ({$regularCount} xe chốt theo km hiện tại, {$deferredCount} xe đang chạy chuyến để sang tháng sau tính theo start_km của chuyến); lưu km kỳ trước cho {$recorded} xe.";

        Log::info("[SnapshotMonthlyVehicleKm] {$message}");
        $this->info($message);

        return self::SUCCESS;
    }

    /**
     * Km đầu kỳ mới của từng xe: xe đang chạy chuyến lấy start_km của chuyến (fallback km hiện tại)
     * để cả chuyến tính sang kỳ sau; các xe còn lại lấy km hiện tại.
     *
     * @return array{all: array<int, float>, deferred: array<int, true>}
     */
    private function newStartKmByVehicle(): array
    {
        $inProgressStatuses = [
            TripStatus::Started->value,
            TripStatus::ArrivedPickup->value,
            TripStatus::Delivering->value,
            TripStatus::ArrivedDelivery->value,
            TripStatus::Delivered->value,
            TripStatus::DriverSwap->value,
            TripStatus::ReturnTrip->value,
        ];

        // Chuyến đang chạy mới nhất của từng xe
        $activeTripStartKm = Trip::query()
            ->whereIn('status', $inProgressStatuses)
            ->whereNull('completed_at')
            ->whereNotNull('vehicle_id')
            ->orderBy('id', 'desc')
            ->get(['id', 'vehicle_id', 'start_km'])
            ->unique('vehicle_id')
            ->mapWithKeys(fn (Trip $trip): array => [$trip->vehicle_id => $trip->start_km]);

        $currentMileage = Vehicle::query()->pluck('current_mileage', 'id');

        $all = [];
        $deferred = [];

        foreach ($activeTripStartKm as $vehicleId => $startKm) {
            $effectiveKm = $startKm ?? $currentMileage[$vehicleId] ?? null;
            if ($effectiveKm !== null) {
                $all[$vehicleId] = (float) $effectiveKm;
                $deferred[$vehicleId] = true;
            }
        }

        foreach ($currentMileage as $vehicleId => $mileage) {
            if ($mileage !== null && ! $activeTripStartKm->has($vehicleId)) {
                $all[$vehicleId] = (float) $mileage;
            }
        }

        return ['all' => $all, 'deferred' => $deferred];
    }

    /**
     * Ghi km của kỳ vừa kết thúc; bỏ qua xe chưa có km đầu kỳ hoặc đã ghi cho cùng thời điểm chốt (chạy lại lệnh).
     */
    private function recordCycle(Vehicle $vehicle, float $endKm, Carbon $cycleStart, Carbon $cycleEnd): bool
    {
        if ($vehicle->monthly_start_km === null) {
            return false;
        }

        $alreadyRecorded = VehicleKmCycle::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('cycle_end', '>=', $cycleEnd->copy()->startOfDay())
            ->exists();

        if ($alreadyRecorded) {
            return false;
        }

        $startKm = (float) $vehicle->monthly_start_km;
        $kmRun = max(0.0, $endKm - $startKm);
        $tracksKmOver = $vehicle->tracksKmOver();
        $overKm = $tracksKmOver ? max(0.0, $kmRun - VehicleKmOverChartWidget::KM_LIMIT) : 0.0;

        VehicleKmCycle::create([
            'vehicle_id' => $vehicle->id,
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
        ]);

        return true;
    }
}
