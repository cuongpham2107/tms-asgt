<?php

namespace App\Console\Commands;

use App\Filament\Pages\TripStatisticsReport;
use App\Filament\Widgets\TripStatisticsTable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('trip-stats:warm-cache')]
#[Description('Tính sẵn dòng Bảng thống kê chuyến cho các nút ngày (Hôm nay … Tháng trước) với bộ lọc mặc định, để bấm là có ngay')]
class WarmTripStatisticsCacheCommand extends Command
{
    public function handle(): int
    {
        foreach (array_keys(TripStatisticsReport::DATE_PRESETS) as $preset) {
            [$startDate, $endDate] = TripStatisticsReport::presetDates($preset);
            $startedAt = microtime(true);

            try {
                $table = new TripStatisticsTable;
                $table->startDate = $startDate;
                $table->endDate = $endDate;
                $table->customerId = null;
                $table->vehicleOwner = 'all';
                $table->search = null;
                $table->refreshBaseRowsCache();

                $this->line(sprintf('%s (%s → %s): %d dòng, %.2fs', $preset, $startDate, $endDate, $table->getBaseFilteredRows()->count(), microtime(true) - $startedAt));
            } catch (Throwable $exception) {
                report($exception);
                $this->error("{$preset}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
