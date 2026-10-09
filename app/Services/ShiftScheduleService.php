<?php

namespace App\Services;

use App\Enums\DriverWorkShift;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ShiftScheduleService
{
    /**
     * Xác định ca trực (Chẵn hay Lẻ) tại một thời điểm (mặc định now()).
     * Chu kỳ ca trực bắt đầu từ 08:00 sáng hôm nay đến 08:00 sáng hôm sau:
     * - Nếu thời gian trước 08:00 sáng: tính là ca của ngày hôm trước.
     * - Ngày dương lịch là số chẵn ($day % 2 === 0) => Ca chẵn (DriverWorkShift::Even)
     * - Ngày dương lịch là số lẻ ($day % 2 !== 0) => Ca lẻ (DriverWorkShift::Odd)
     */
    public static function determineShift(?CarbonInterface $time = null): DriverWorkShift
    {
        $shiftDate = self::getShiftDate($time);

        return ($shiftDate->day % 2 === 0)
            ? DriverWorkShift::Even
            : DriverWorkShift::Odd;
    }

    /**
     * Lấy ngày bắt đầu của ca trực theo mốc 08:00 sáng.
     */
    public static function getShiftDate(?CarbonInterface $time = null): Carbon
    {
        $time = $time ? Carbon::parse($time) : Carbon::now();

        return $time->hour < 8
            ? $time->copy()->subDay()->startOfDay()
            : $time->copy()->startOfDay();
    }

    /**
     * Kiểm tra xem một ca trực (hoặc chu kỳ của tài xế) có phải là ca trực của thời điểm đã cho (mặc định now()) hay không.
     */
    public static function isTodayShift(DriverWorkShift|string|null $shift, ?CarbonInterface $time = null): bool
    {
        if ($shift === null) {
            return false;
        }

        $shiftValue = $shift instanceof DriverWorkShift ? $shift : DriverWorkShift::tryFrom($shift);

        return $shiftValue === self::determineShift($time);
    }
}
