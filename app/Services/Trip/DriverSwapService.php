<?php

namespace App\Services\Trip;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Services\Km\ShiftKmCalculatorService;
use App\Services\Km\TripKmCalculatorService;
use App\Services\Km\TripKmService;
use Illuminate\Support\Facades\DB;

/**
 * Đảo lái: các bước đổi trạng thái chuyến / đơn khi bàn giao giữa chừng và khi nhận lại chuyến.
 * Mỗi tình huống một method, giữ đúng thứ tự các bước như trước khi gom về đây.
 * Nơi gọi tự lo phần riêng: mốc bàn giao, bản ghi DriverSwap, ảnh, km xe, thông báo.
 */
class DriverSwapService
{
    public function __construct(
        private readonly TripKmService $tripKmService,
        private readonly TripKmCalculatorService $tripKm,
        private readonly ShiftKmCalculatorService $shiftKm,
    ) {}

    /**
     * Tài xế kết thúc chuyến khi đơn chưa xong (API) hoặc rời xe giữa chuyến: chốt km dở dang rồi chuyển sang Đảo lái.
     *
     * @param  bool  $includeAssignedOrders  API kết thúc chuyến chuyển cả đơn Đã gán; rời xe thì không
     */
    public function handOverWithKm(Trip $trip, float $km, bool $includeAssignedOrders = false): void
    {
        DB::transaction(function () use ($trip, $km, $includeAssignedOrders): void {
            $this->tripKmService->recalculate($trip, $km);

            $trip->end_km = $km;
            $trip->status = TripStatus::DriverSwap;
            $trip->save();

            $statuses = [OrderStatus::Sent, OrderStatus::InTransit];
            if ($includeAssignedOrders) {
                $statuses[] = OrderStatus::Assigned;
            }

            $this->parkOrders($trip, $statuses);
        });
    }

    /**
     * Tài xế khác nhận chuyến (từ App hoặc điều hành): đổi tài xế, gỡ ca cũ, rồi tính lại km ca.
     */
    public function handOverToDriver(Trip $trip, int $toDriverId): void
    {
        DB::transaction(function () use ($trip, $toDriverId): void {
            $trip->driver_id = $toDriverId;
            $trip->status = TripStatus::DriverSwap;
            $trip->shift_id = null;
            $trip->save();

            $this->parkOrders($trip, [OrderStatus::Sent, OrderStatus::InTransit]);

            $this->shiftKm->calculateForTrip($trip);
        });
    }

    /**
     * Điều hành kết thúc ca khi tài xế còn chuyến dở: chốt km chuyến (nếu có km), gỡ ca. Km ca do bước đóng ca tính.
     */
    public function handOverOnShiftEnd(Trip $trip, ?float $km): void
    {
        DB::transaction(function () use ($trip, $km): void {
            if ($km !== null && $km > 0) {
                $this->tripKm->calculate($trip, endKm: $km);
                $trip->refresh();
            }

            $trip->status = TripStatus::DriverSwap;
            $trip->shift_id = null;
            $trip->save();

            $this->parkOrders($trip, [OrderStatus::Sent, OrderStatus::InTransit]);
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

    /**
     * @param  list<OrderStatus>  $statuses
     */
    private function parkOrders(Trip $trip, array $statuses): void
    {
        $trip->orders()
            ->whereIn('status', array_map(fn (OrderStatus $status): string => $status->value, $statuses))
            ->get()
            ->each->update(['status' => OrderStatus::DriverSwap]);
    }
}
