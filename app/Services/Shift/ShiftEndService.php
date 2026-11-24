<?php

namespace App\Services\Shift;

use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Services\Km\ShiftKmCalculatorService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Đóng hồ sơ ca: dùng chung cho App tài xế, điều hành và tự đóng ca (08:00 / 20:00).
 * Nơi gọi tự quyết định km / giờ kết thúc, chặn hoặc bàn giao chuyến dở và cập nhật km xe.
 */
class ShiftEndService
{
    public function __construct(private readonly ShiftKmCalculatorService $shiftKm) {}

    /**
     * Ghi giờ / GPS kết thúc, tính lại km ca, gỡ ca khỏi các chuyến đã đảo lái và chuyến chờ chạy (để ca sau nhận lại).
     *
     * Km kết thúc không lưu trên ca: mỗi ca có thể chạy nhiều xe nên mốc km nằm ở
     * trip_checkpoints (vehicle_id + km_reading) của từng xe.
     *
     * @param  array{end_gps_lat?: string|float|null, end_gps_lng?: string|float|null}  $gps
     */
    public function close(DriverShift $shift, CarbonInterface $endTime, array $gps = []): DriverShift
    {
        return DB::transaction(function () use ($shift, $endTime, $gps): DriverShift {
            $shift->end_time = $endTime;
            if (array_key_exists('end_gps_lat', $gps)) {
                $shift->end_gps_lat = $gps['end_gps_lat'];
            }
            if (array_key_exists('end_gps_lng', $gps)) {
                $shift->end_gps_lng = $gps['end_gps_lng'];
            }
            $shift->save();

            $this->shiftKm->calculate($shift);

            Trip::query()
                ->where('driver_id', $shift->driver_id)
                ->where('shift_id', $shift->id)
                ->where('status', TripStatus::DriverSwap)
                ->get()
                ->each->update(['shift_id' => null]);

            Trip::query()
                ->where('driver_id', $shift->driver_id)
                ->where('shift_id', $shift->id)
                ->where('status', TripStatus::Pending)
                ->update(['shift_id' => null]);

            return $shift;
        });
    }
}
