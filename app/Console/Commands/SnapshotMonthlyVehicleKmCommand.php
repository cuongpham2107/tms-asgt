<?php

namespace App\Console\Commands;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('vehicles:snapshot-monthly-km')]
#[Description('Chốt số Km đầu chu kỳ tháng lúc 12:00 ngày 23 hàng tháng (các chuyến đang chạy lấy start_km để tính sang tháng sau)')]
class SnapshotMonthlyVehicleKmCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Đang bắt đầu chốt Km bắt đầu chu kỳ tháng (12:00 ngày 23) cho các xe...');

        // 1. Tìm các chuyến đang chạy thực tế (chưa hoàn thành/kết thúc)
        $inProgressStatuses = [
            TripStatus::Started->value,
            TripStatus::ArrivedPickup->value,
            TripStatus::Delivering->value,
            TripStatus::ArrivedDelivery->value,
            TripStatus::Delivered->value,
            TripStatus::DriverSwap->value,
            TripStatus::ReturnTrip->value,
        ];

        // Lấy chuyến đang chạy mới nhất của từng xe
        $activeTrips = Trip::query()
            ->whereIn('status', $inProgressStatuses)
            ->whereNull('completed_at')
            ->whereNotNull('vehicle_id')
            ->orderBy('id', 'desc')
            ->get(['id', 'vehicle_id', 'start_km']);

        $vehicleStartKmFromTrip = [];
        foreach ($activeTrips as $trip) {
            if (! isset($vehicleStartKmFromTrip[$trip->vehicle_id])) {
                $vehicleStartKmFromTrip[$trip->vehicle_id] = $trip->start_km !== null
                    ? (float) $trip->start_km
                    : null;
            }
        }

        $activeVehicleIds = array_keys($vehicleStartKmFromTrip);
        $deferredCount = 0;

        // Cập nhật cho các xe đang có chuyến đang chạy:
        // Lấy start_km của chuyến (nếu null fallback current_mileage) làm mốc đầu tháng mới
        // để toàn bộ quãng đường chuyến này được tính trọn vẹn vào tháng sau
        foreach ($vehicleStartKmFromTrip as $vehicleId => $startKm) {
            $effectiveKm = $startKm ?? Vehicle::where('id', $vehicleId)->value('current_mileage');
            if ($effectiveKm !== null) {
                Vehicle::where('id', $vehicleId)->update(['monthly_start_km' => $effectiveKm]);
                $deferredCount++;
            }
        }

        // Cập nhật cho các xe còn lại (không có chuyến đang chạy):
        // monthly_start_km = current_mileage
        $regularCount = Vehicle::query()
            ->whereNotNull('current_mileage')
            ->when(! empty($activeVehicleIds), fn ($q) => $q->whereNotIn('id', $activeVehicleIds))
            ->update([
                'monthly_start_km' => DB::raw('current_mileage'),
            ]);

        $totalUpdated = $deferredCount + $regularCount;
        $message = "Đã hoàn thành chốt Km đầu chu kỳ tháng cho {$totalUpdated} xe ({$regularCount} xe chốt theo km hiện tại, {$deferredCount} xe đang chạy chuyến để sang tháng sau tính theo start_km của chuyến).";

        Log::info("[SnapshotMonthlyVehicleKm] {$message}");
        $this->info($message);

        return self::SUCCESS;
    }
}
