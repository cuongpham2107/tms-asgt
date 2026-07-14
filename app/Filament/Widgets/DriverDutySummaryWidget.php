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
    protected int|array|null $columns = 4;

    protected function getStats(): array
    {
        $drivers = User::role('driver')->where('is_active', true)->get();

        $stats = [];
        $grandTotal = 0;
        $grandWorking = 0;

        foreach (OnDutyLocation::cases() as $station) {
            $stationDrivers = $drivers->filter(fn (User $d) => $d->station === $station);
            $total = $stationDrivers->count();
            if ($total === 0) {
                continue;
            }

            $grandTotal += $total;

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

            $grandWorking += $working;
            $off = $total - $working;

            $shiftBreakdown = [];
            if ($full > 0) {
                $shiftBreakdown[] = "Cả:{$full}";
            }
            if ($morningHalf > 0) {
                $shiftBreakdown[] = "Sáng:{$morningHalf}";
            }
            if ($nightHalf > 0) {
                $shiftBreakdown[] = "Tối:{$nightHalf}";
            }

            $descParts = ["Đi làm: {$working}"];
            if (! empty($shiftBreakdown)) {
                $descParts[] = '('.implode(' ', $shiftBreakdown).')';
            }
            $descParts[] = "· Nghỉ: {$off}";

            $stats[] = Stat::make(
                $station->getLabel(),
                (string) $total
            )
                ->description(implode(' ', $descParts))
                ->descriptionIcon('heroicon-m-user-group')
                ->color($station->getColor());
        }

        // Tổng hàng
        $grandOff = $grandTotal - $grandWorking;
        $stats[] = Stat::make('Tổng', (string) $grandTotal)
            ->description("Đi làm: {$grandWorking} · Nghỉ: {$grandOff}")
            ->descriptionIcon('heroicon-m-users')
            ->color('gray');

        return $stats;
    }
}
