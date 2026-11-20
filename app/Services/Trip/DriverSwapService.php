<?php

namespace App\Services\Trip;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Services\Km\ShiftKmCalculatorService;
use App\Services\Km\TripKmCalculatorService;
use Illuminate\Support\Facades\DB;

/**
 * Đảo lái: một chỗ duy nhất đổi trạng thái chuyến / đơn khi bàn giao giữa chừng và khi nhận lại chuyến.
 * Nơi gọi (App tài xế, điều hành, kết thúc ca, rời xe) tự lo phần riêng: mốc bàn giao, bản ghi DriverSwap, ảnh, km xe, thông báo.
 */
class DriverSwapService
{
    /** Đơn còn đang chạy thì chuyển sang chờ tài xế nhận tiếp. */
    private const HANDOVER_ORDER_STATUSES = [OrderStatus::Assigned, OrderStatus::Sent, OrderStatus::InTransit];

    public function __construct(
        private readonly TripKmCalculatorService $tripKm,
        private readonly ShiftKmCalculatorService $shiftKm,
    ) {}

    /**
     * Bàn giao chuyến đang chạy.
     *
     * @param  float|null  $closingKm  km chốt phần đã chạy (tính km dở dang, ghi end_km); null = không chốt km
     * @param  int|null  $toDriverId  tài xế nhận chuyến (null = chưa có, điều hành gán sau)
     * @param  bool  $detachShift  gỡ chuyến khỏi ca của tài xế cũ
     */
    public function handOver(Trip $trip, ?float $closingKm = null, ?int $toDriverId = null, bool $detachShift = false): void
    {
        DB::transaction(function () use ($trip, $closingKm, $toDriverId, $detachShift): void {
            if ($closingKm !== null && $closingKm > 0) {
                $this->tripKm->calculate($trip, endKm: $closingKm);
                $trip->refresh();
                $trip->end_km = $closingKm;
            }

            $trip->status = TripStatus::DriverSwap;
            if ($toDriverId !== null) {
                $trip->driver_id = $toDriverId;
            }
            if ($detachShift) {
                $trip->shift_id = null;
            }
            $trip->save();

            $trip->orders()
                ->whereIn('status', array_map(fn (OrderStatus $status): string => $status->value, self::HANDOVER_ORDER_STATUSES))
                ->get()
                ->each->update(['status' => OrderStatus::DriverSwap]);

            $this->shiftKm->calculateForTrip($trip);
        });
    }

    /**
     * Tài xế mới nhận lại chuyến: đơn đang chờ đảo lái quay về đúng bước theo trạng thái chuyến.
     */
    public function resume(Trip $trip, TripStatus $resumedStatus): void
    {
        $restored = in_array($resumedStatus, [TripStatus::Delivering, TripStatus::ArrivedDelivery, TripStatus::Delivered], true)
            ? OrderStatus::InTransit
            : OrderStatus::Sent;

        $trip->orders()
            ->where('status', OrderStatus::DriverSwap->value)
            ->get()
            ->each->update(['status' => $restored]);
    }
}
