<?php

namespace App\Filament\Widgets;

use App\Enums\OnDutyLocation;
use App\Enums\ShiftType;
use App\Models\DriverShift;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DriverDutySummaryWidget extends BaseWidget
{
    protected int|array|null $columns = 3;

    protected function getStats(): array
    {
        $drivers = User::role('driver')->where('is_active', true)->get();

        $stats = [];

        foreach (OnDutyLocation::cases() as $station) {
            $stationDrivers = $drivers->filter(fn (User $d) => $d->station === $station);
            $total = $stationDrivers->count();
            if ($total === 0) {
                continue;
            }

            $working = 0;
            $full = 0;
            $morningHalf = 0;
            $nightHalf = 0;

            foreach ($stationDrivers as $driver) {
                $shift = DriverShift::where('driver_id', $driver->id)
                    ->whereNull('end_time')
                    ->first();

                if ($shift !== null) {
                    $working++;
                    match ($shift->shift_type) {
                        ShiftType::Full => $full++,
                        ShiftType::MorningHalf => $morningHalf++,
                        ShiftType::NightHalf => $nightHalf++,
                    };
                }
            }

            $off = $total - $working;

            $stats[] = Stat::make(
                $station->getLabel(),
                number_format($total)
            )
                ->description("Đi làm: {$working} (Cả:{$full} Sáng:{$morningHalf} Tối:{$nightHalf}) · Nghỉ: {$off}")
                ->descriptionIcon('heroicon-m-user-group')
                ->color($station->getColor());
        }

        return $stats;
    }
}
