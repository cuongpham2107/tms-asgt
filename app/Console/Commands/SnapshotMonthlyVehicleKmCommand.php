<?php

namespace App\Console\Commands;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Km\VehicleKmCycleRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('vehicles:snapshot-monthly-km')]
#[Description('Chốt số Km đầu chu kỳ tháng lúc 12:00 ngày 23 hàng tháng (các chuyến đang chạy lấy start_km để tính sang tháng sau) và lưu km của kỳ vừa kết thúc')]
class SnapshotMonthlyVehicleKmCommand extends Command
{
    public function handle(VehicleKmCycleRecorder $recorder): int
    {
        $this->info('Đang bắt đầu chốt Km bắt đầu chu kỳ tháng (12:00 ngày 23) cho các xe...');

        $cycleEnd = now()->startOfMinute();
        // Kỳ vừa kết thúc ở mốc 12:00 ngày 23 gần nhất (chạy lại trong ngày vẫn là kỳ đó).
        $cycleStart = VehicleKmCycleRecorder::cycleStartFor($cycleEnd)->subMonthNoOverflow();
        $newStartKm = $this->newStartKmByVehicle();

        $recorded = 0;
        $deferredCount = 0;
        $regularCount = 0;

        DB::transaction(function () use ($recorder, $newStartKm, $cycleStart, $cycleEnd, &$recorded, &$deferredCount, &$regularCount): void {
            $vehicles = Vehicle::query()->whereIn('id', array_keys($newStartKm['all']))->get();

            foreach ($vehicles as $vehicle) {
                $endKm = $newStartKm['all'][$vehicle->id];

                // Lưu kỳ vừa kết thúc trước khi ghi đè km đầu kỳ (cùng công thức cột KM over trên bảng điều xe).
                if ($recorder->record($vehicle, $endKm, $cycleStart, $cycleEnd)) {
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
}
