<?php

namespace App\Console\Commands;

use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripDriverAssignment;
use App\Services\ShiftKmCalculatorService;
use App\Services\TripKmCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalculateGpsKm extends Command
{
    protected $signature = 'gps:calculate-km';

    protected $description = 'Chốt km (tổng / có hàng / không hàng) từ GPS cho chuyến đã kết thúc và ca đã đóng';

    public function handle(TripKmCalculatorService $tripCalculator, ShiftKmCalculatorService $shiftCalculator): int
    {
        $trips = Trip::query()
            ->whereIn('status', [TripStatus::Completed, TripStatus::Cancelled])
            ->whereNotNull('started_at')
            ->whereNull('km_calculated_at')
            ->whereNull('km_adjusted')
            ->where(fn ($q) => $q
                ->where('completed_at', '<=', now()->subMinutes(config('gps.settle_after_minutes')))
                ->orWhere(fn ($q) => $q->whereNull('completed_at')->where('cancelled_at', '<=', now()->subMinutes(config('gps.settle_after_minutes')))))
            ->get();

        foreach ($trips as $trip) {
            try {
                $tripCalculator->calculate($trip);

                // Km có hàng của lượt lái thay đổi → tính lại km các ca liên quan.
                DriverShift::query()
                    ->whereIn('id', TripDriverAssignment::where('trip_id', $trip->id)->whereNotNull('shift_id')->select('shift_id'))
                    ->update(['km_calculated_at' => null]);
            } catch (Throwable $e) {
                Log::error('Tính km GPS cho chuyến thất bại', ['trip_id' => $trip->id, 'error' => $e->getMessage()]);
            }
        }

        $shifts = DriverShift::query()
            ->where('end_time', '<=', now()->subMinutes(config('gps.settle_after_minutes')))
            ->whereNull('km_calculated_at')
            ->get();

        foreach ($shifts as $shift) {
            try {
                $shiftCalculator->calculate($shift);
            } catch (Throwable $e) {
                Log::error('Tính km GPS cho ca thất bại', ['shift_id' => $shift->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Đã tính km cho {$trips->count()} chuyến và {$shifts->count()} ca.");

        return self::SUCCESS;
    }
}
