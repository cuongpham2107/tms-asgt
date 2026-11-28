<?php

namespace App\Services\Order;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gán xe / gửi lệnh / thu hồi đơn: một chỗ duy nhất tạo chuyến, đổi trạng thái đơn và xe.
 * Thông báo cho tài xế do nơi gọi gửi (cần dữ liệu đơn trước khi đổi).
 * Cập nhật trên bản đọc mới của đơn để Nhật ký ghi đủ mà biến đơn của nơi gọi giữ nguyên.
 */
class OrderDispatchService
{
    /**
     * Tạo chuyến chờ chạy cho đơn và gán xe / lái xe.
     *
     * @param  array<string, mixed>  $extraOrderUpdates  ví dụ chargeable_weight khi gán xe đơn hàng ngoài
     */
    public function assign(Order $order, int $vehicleId, ?int $driverId, OrderStatus $status = OrderStatus::Sent, array $extraOrderUpdates = []): Trip
    {
        return DB::transaction(function () use ($order, $vehicleId, $driverId, $status, $extraOrderUpdates): Trip {
            $trip = Trip::create([
                'trip_code' => Trip::generateTripCode(),
                'vehicle_id' => $vehicleId,
                'driver_id' => $driverId,
                'status' => TripStatus::Pending,
                'start_location_id' => $extraOrderUpdates['pickup_location_id'] ?? $order->pickup_location_id,
                'end_location_id' => $order->deliveryPoints()->orderBy('sequence', 'desc')->first()?->location_id,
            ]);

            $updates = ['trip_id' => $trip->id, 'status' => $status, ...$extraOrderUpdates];
            if ($status === OrderStatus::Sent) {
                $updates['sent_at'] = now();
            }

            if (! Order::query()->findOrFail($order->id)->update($updates)) {
                throw new \RuntimeException('Không thể gán đơn hàng vào chuyến.');
            }

            Vehicle::query()->find($vehicleId)?->update(['status' => VehicleStatus::Running]);

            $order->setAttribute('trip_id', $trip->id);

            return $trip;
        });
    }

    /**
     * Gửi lệnh cho các đơn đã gán xe.
     *
     * @param  Collection<int, Order>|Order  $orders
     */
    public function send(Collection|Order $orders): void
    {
        $ids = $orders instanceof Order ? [$orders->id] : $orders->pluck('id')->all();

        Order::query()->whereIn('id', $ids)->get()->each->update([
            'status' => OrderStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * Thu hồi đơn về Nháp: gỡ khỏi chuyến, xoá chuyến nếu không còn đơn.
     */
    public function unsend(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $trip = $order->trip_id !== null ? Trip::query()->find($order->trip_id) : null;

            Order::query()->findOrFail($order->id)->update([
                'status' => OrderStatus::Draft,
                'trip_id' => null,
                'sent_at' => null,
            ]);

            if ($trip !== null && $trip->orders()->count() === 0) {
                $trip->delete();
                // Xe không còn đơn / chuyến nào đang chạy thì về sẵn sàng.
                Vehicle::syncIdleStatus($trip->vehicle_id);
            }
        });
    }
}
