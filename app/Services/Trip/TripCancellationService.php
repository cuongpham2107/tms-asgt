<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\Trip;
use App\Services\Km\ShiftKmCalculatorService;
use App\Services\Km\TripKmCalculatorService;
use Illuminate\Support\Facades\DB;

/**
 * Huỷ chuyến (nút Huỷ chuyến, có km) và huỷ đơn (danh sách Đơn hàng). Giữ đúng các bước như trước khi gom về đây.
 */
class TripCancellationService
{
    public function __construct(
        private readonly TripKmCalculatorService $tripKm,
        private readonly ShiftKmCalculatorService $shiftKm,
    ) {}

    /**
     * Điều hành huỷ chuyến với km lúc huỷ: chốt km chuyến, xe về sẵn sàng, huỷ các đơn chưa đóng, tạo mốc huỷ, tính lại km ca.
     */
    public function cancelTrip(Trip $trip, float $km, ?string $reason = null): void
    {
        DB::transaction(function () use ($trip, $km, $reason): void {
            // Tính partial KM
            $this->tripKm->calculate($trip, endKm: $km);
            $trip->refresh();

            $trip->end_km = $km;
            $trip->status = TripStatus::Cancelled;
            $trip->cancelled_at = now();
            $trip->save();

            // Cập nhật km xe và đưa xe về trạng thái sẵn sàng
            if ($trip->vehicle) {
                $trip->vehicle->current_mileage = $km;
                $trip->vehicle->status = VehicleStatus::On;
                $trip->vehicle->save();
            }

            $trip->orders()
                ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
                ->get()
                ->each->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason ?? '',
                ]);

            $trip->checkpoints()->create([
                'checkpoint_type' => CheckpointType::Cancelled->value,
                'km_reading' => $km,
                'occurred_at' => now(),
                'driver_id' => $trip->driver_id,
                'shift_id' => $trip->shift_id,
            ]);

            if ($trip->shift_id) {
                $this->shiftKm->calculate($trip->shift);
            }
        });
    }

    /**
     * Huỷ đơn: đơn huỷ với lý do; mỗi chuyến chỉ có một đơn nên chuyến của đơn chuyển sang Huỷ.
     * Cập nhật trên bản đọc mới để biến đơn của nơi gọi (dùng gửi thông báo) giữ dữ liệu cũ.
     */
    public function cancelOrder(Order $order, ?string $reason = null): void
    {
        Order::query()->findOrFail($order->id)->update([
            'status' => OrderStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        if ($order->trip_id !== null) {
            Trip::query()->whereKey($order->trip_id)->get()->each->update([
                'status' => TripStatus::Cancelled,
            ]);
        }
    }
}
