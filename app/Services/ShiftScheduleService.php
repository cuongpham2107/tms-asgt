<?php

namespace App\Services;

use App\Enums\DriverWorkShift;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ShiftScheduleService
{
    /**
     * Mốc ngày chuẩn để tính chu kỳ luân phiên ca trực.
     * Mốc: 01/10/2026 (Ngày 1/10/2026 là Ca Lẻ).
     * Cứ mỗi ngày trôi qua (+1 ngày) thì đổi ca một lần.
     */
    public const BASE_DATE = '2026-10-01';

    /**
     * Xác định ca trực (Chẵn hay Lẻ) tại một thời điểm (mặc định now()).
     * Chu kỳ ca trực bắt đầu từ 08:00 sáng hôm nay đến 08:00 sáng hôm sau:
     * - Nếu thời gian trước 08:00 sáng: tính là ca của ngày hôm trước.
     * - Tính số ngày thực tế chênh lệch A = shiftDate - 2026-10-01.
     * - A là số chẵn (0, 2, 4...) => Ca lẻ (DriverWorkShift::Odd, vì ngày 1/10 là Ca lẻ).
     * - A là số lẻ (1, 3, 5...) => Ca chẵn (DriverWorkShift::Even, vì ngày 2/10 là Ca chẵn).
     */
    public static function determineShift(?CarbonInterface $time = null): DriverWorkShift
    {
        $shiftDate = self::getShiftDate($time);
        $baseDate = Carbon::parse(self::BASE_DATE)->startOfDay();

        $daysDiff = (int) $baseDate->diffInDays($shiftDate, false);

        return (abs($daysDiff) % 2 === 0)
            ? DriverWorkShift::Odd
            : DriverWorkShift::Even;
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
