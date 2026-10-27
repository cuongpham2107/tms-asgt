<?php

namespace App\Services;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoEndShiftService
{
    /**
     * Thời gian ân hạn (phút) sau mốc kết thúc ca (mặc định 0 phút: thoát đúng mốc 08:00 và 20:00).
     */
    public const GRACE_MINUTES = 0;

    /**
     * Thời lượng tối thiểu (giờ) của một ca cả ngày — dùng để chặn trường hợp ca vào
     * sát mốc 08:00 bị tính là đã hết hạn ngay trong ngày.
     */
    public const MIN_DAY_SHIFT_HOURS = 4;

    public function __construct(
        protected ShiftKmCalculatorService $shiftKmCalculatorService
    ) {}

    /**
     * Tính thời điểm kết thúc chuẩn (deadline) của một ca làm việc.
     * - Ca X/2 (MorningHalf): 20:00 cùng ngày
     * - Ca X (Full): 08:00 sáng ngày hôm sau
     * - Ca Y/2 (NightHalf): 08:00 sáng ngày hôm sau (hoặc 08:00 cùng ngày nếu bắt đầu sau 00:00 và trước 08:00)
     */
    public function getShiftDeadline(DriverShift $shift): Carbon
    {
        $startTime = Carbon::parse($shift->start_time);
        $shiftDate = ShiftScheduleService::getShiftDate($startTime);

        $deadline = match ($shift->shift_type) {
            ShiftType::MorningHalf => $shiftDate->copy()->setTime(20, 0, 0),
            ShiftType::Full, ShiftType::NightHalf => $shiftDate->copy()->addDay()->setTime(8, 0, 0),
            default => $shiftDate->copy()->addDay()->setTime(8, 0, 0),
        };

        // Mốc kết thúc phải sau giờ vào ca một khoảng tối thiểu. Trước 08:00, getShiftDate() tính về
        // ngày hôm trước, nên ca cả ngày vào sáng sớm (vd 07:30) từng bị coi là quá hạn ngay 08:00
        // cùng ngày và bị auto-end chỉ sau vài chục phút.
        $minimumHours = in_array($shift->shift_type, [ShiftType::Full, ShiftType::NightHalf], true)
            ? self::MIN_DAY_SHIFT_HOURS
            : 0;
        $threshold = $startTime->copy()->addHours($minimumHours);
        $cycleHours = $shift->shift_type === ShiftType::MorningHalf ? 12 : 24;

        while ($deadline->lte($threshold)) {
            $deadline->addHours($cycleHours);
        }

        return $deadline;
    }

    /**
     * Kiểm tra xem ca đã đến hạn tự động thoát (sau thời gian ân hạn 5 phút) hay chưa.
     */
    public function isPastGracePeriod(DriverShift $shift, ?CarbonInterface $now = null): bool
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $deadline = $this->getShiftDeadline($shift);

        return $now->gte($deadline->copy()->addMinutes(self::GRACE_MINUTES));
    }

    /**
     * Kiểm tra xem tài xế có chuyến/đơn nào đang làm dở (Sent / InTransit) hay không.
     * Lưu ý: Nếu đơn/chuyến đã bàn giao đảo lái (DriverSwap) thì KHÔNG coi là làm dở.
     */
    public function hasIncompleteOrders(DriverShift $shift): bool
    {
        return Trip::where('driver_id', $shift->driver_id)
            ->where(function ($query) {
                $query->whereHas('orders', function ($q) {
                    $q->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value]);
                })->orWhere(function ($q) {
                    $q->where('is_empty_run', true)
                        ->whereIn('status', [
                            TripStatus::Started,
                            TripStatus::ArrivedPickup,
                            TripStatus::Delivering,
                            TripStatus::ArrivedDelivery,
                            TripStatus::Delivered,
                            TripStatus::ReturnTrip,
                        ]);
                });
            })
            ->whereIn('status', [
                TripStatus::Started,
                TripStatus::ArrivedPickup,
                TripStatus::Delivering,
                TripStatus::ArrivedDelivery,
                TripStatus::Delivered,
                TripStatus::ReturnTrip,
            ])
            ->exists();
    }

    /**
     * Quét và tự động thoát tất cả các ca đủ điều kiện.
     *
     * @return Collection<int, DriverShift> Danh sách các ca đã được tự động thoát
     */
    public function autoEndEligibleShifts(?CarbonInterface $now = null, bool $dryRun = false): Collection
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();

        $openShifts = DriverShift::query()
            ->whereNull('end_time')
            ->with(['driver', 'trips.vehicle'])
            ->get();

        $endedShifts = collect();

        foreach ($openShifts as $shift) {
            if (! $this->isPastGracePeriod($shift, $now)) {
                continue;
            }

            if ($this->hasIncompleteOrders($shift)) {
                Log::info("[AutoEndShift] Bỏ qua ca #{$shift->id} của lái xe {$shift->driver?->name} vì còn đơn/chuyến đang làm dở.");

                continue;
            }

            if ($dryRun) {
                DB::beginTransaction();
                try {
                    $this->endShift($shift, $now);
                    $endedShifts->push(clone $shift);
                } finally {
                    DB::rollBack();
                }
            } else {
                $this->endShift($shift, $now);
                $endedShifts->push($shift);
            }
        }

        return $endedShifts;
    }

    /**
     * Thực hiện tự động kết thúc một ca trực.
     */
    public function endShift(DriverShift $shift, ?CarbonInterface $now = null): DriverShift
    {
        return DB::transaction(function () use ($shift) {
            $deadline = $this->getShiftDeadline($shift);

            // 1. Xác định end_km theo thứ tự ưu tiên:
            // a. Checkpoint 'end' hoặc 'driver_swap' gần nhất của ca
            $endCheckpoint = TripCheckpoint::where('shift_id', $shift->id)
                ->whereIn('checkpoint_type', [CheckpointType::End->value, CheckpointType::DriverSwap->value])
                ->whereNotNull('km_reading')
                ->latest('id')
                ->first();

            $vehicle = $shift->trips()->latest('started_at')->first()?->vehicle
                ?? $shift->driver?->vehiclesAsDriver()->first();

            if ($endCheckpoint !== null) {
                $endKm = (float) $endCheckpoint->km_reading;
            } else {
                // b. Lấy từ chuyến đi hoàn thành gần nhất
                $latestTrip = $shift->trips()->whereNotNull('end_km')->latest('completed_at')->first();
                // c. Checkpoint bất kỳ có km_reading gần nhất trong ca
                $latestCp = TripCheckpoint::where('shift_id', $shift->id)->whereNotNull('km_reading')->latest('id')->first();

                if ($latestTrip?->end_km !== null) {
                    $endKm = (float) $latestTrip->end_km;
                } elseif ($latestCp?->km_reading !== null) {
                    $endKm = (float) $latestCp->km_reading;
                } elseif ($vehicle?->current_mileage !== null) {
                    $endKm = (float) $vehicle->current_mileage;
                } else {
                    // d. Không có chuyến nào -> end_km = start_km
                    $endKm = (float) ($shift->start_km ?? 0);
                }

                $endKm = max((float) ($shift->start_km ?? 0), $endKm);

                // Tạo checkpoint End hệ thống
                TripCheckpoint::create([
                    'checkpoint_type' => CheckpointType::End->value,
                    'trip_id' => null,
                    'shift_id' => $shift->id,
                    'driver_id' => $shift->driver_id,
                    'vehicle_id' => $vehicle?->id,
                    'km_reading' => $endKm,
                    'occurred_at' => $deadline,
                ]);
            }

            // 2. Xác định end_time: chuẩn theo mốc deadline của ca, nhưng không nhỏ hơn checkpoint cuối
            $latestActivity = TripCheckpoint::where('shift_id', $shift->id)->max('occurred_at');
            $recordedEndTime = $latestActivity && Carbon::parse($latestActivity)->gt($deadline)
                ? Carbon::parse($latestActivity)
                : $deadline;

            $shift->end_time = $recordedEndTime;
            $shift->end_km = $endKm;
            $shift->save();

            // 3. Tính toán lại toàn bộ KM ca
            $this->shiftKmCalculatorService->calculate($shift);

            // 4. Dọn dẹp liên kết shift_id cho các chuyến driver_swap (nếu có)
            $driverSwappedTrips = Trip::where('driver_id', $shift->driver_id)
                ->where('shift_id', $shift->id)
                ->where('status', TripStatus::DriverSwap)
                ->get();

            foreach ($driverSwappedTrips as $trip) {
                $trip->shift_id = null;
                $trip->save();
            }

            // 5. Cập nhật current_mileage cho xe nếu có
            if ($vehicle && $endKm > 0 && (float) ($vehicle->current_mileage ?? 0) < $endKm) {
                $vehicle->update(['current_mileage' => $endKm]);
            }

            Log::info("[AutoEndShift] Đã tự động kết thúc ca #{$shift->id} cho tài xế {$shift->driver?->name} tại mốc {$recordedEndTime->format('Y-m-d H:i:s')}, end_km: {$endKm}");

            return $shift;
        });
    }
}
