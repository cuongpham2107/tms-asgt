<?php

namespace App\Console\Commands;

use App\Filament\Widgets\VehicleKmOverChartWidget;
use App\Models\Vehicle;
use App\Services\Km\VehicleKmCycleRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('vehicles:record-km-cycle {--dry-run : Chỉ in danh sách, không lưu}')]
#[Description('Lưu KM over đang có trong xe (km hiện tại − km chốt ngày 23) thành bản chốt của kỳ đang chạy, không đổi km đầu kỳ của xe')]
class RecordVehicleKmCycleCommand extends Command
{
    public function handle(VehicleKmCycleRecorder $recorder): int
    {
        $cycleEnd = now()->startOfMinute();
        $cycleStart = VehicleKmCycleRecorder::cycleStartFor($cycleEnd);

        $vehicles = Vehicle::query()
            ->whereNotNull('monthly_start_km')
            ->whereNotNull('current_mileage')
            ->orderBy('plate_number')
            ->get();

        $this->info('Kỳ '.$cycleStart->format('H:i d/m/Y').' → '.$cycleEnd->format('H:i d/m/Y').': '.$vehicles->count().' xe có km đầu kỳ và km hiện tại.');

        $tracked = $vehicles->filter(fn (Vehicle $vehicle): bool => $vehicle->tracksKmOver());
        $this->table(
            ['Biển số', 'Chủ xe', 'Km đầu kỳ', 'Km hiện tại', 'Km đã chạy', 'Km vượt'],
            $tracked->map(fn (Vehicle $vehicle): array => [
                $vehicle->plate_number,
                $vehicle->owner,
                number_format((float) $vehicle->monthly_start_km, 0, ',', '.'),
                number_format((float) $vehicle->current_mileage, 0, ',', '.'),
                number_format(max(0, (float) $vehicle->current_mileage - (float) $vehicle->monthly_start_km), 0, ',', '.'),
                number_format(max(0, (float) $vehicle->current_mileage - (float) $vehicle->monthly_start_km - VehicleKmOverChartWidget::KM_LIMIT), 0, ',', '.'),
            ])->all(),
        );

        if ($this->option('dry-run')) {
            $this->comment('Chạy thử: chưa lưu gì.');

            return self::SUCCESS;
        }

        $recorded = DB::transaction(fn (): int => $vehicles
            ->filter(fn (Vehicle $vehicle): bool => $recorder->record($vehicle, (float) $vehicle->current_mileage, $cycleStart, $cycleEnd))
            ->count());

        $this->info("Đã lưu bản chốt kỳ cho {$recorded} xe. Job chốt km lúc 12:00 ngày 23 sẽ cập nhật lại bằng số cuối của kỳ.");

        return self::SUCCESS;
    }
}
