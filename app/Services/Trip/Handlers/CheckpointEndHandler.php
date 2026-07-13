<?php

namespace App\Services\Trip\Handlers;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Trip;

class CheckpointEndHandler implements CheckpointHandlerInterface
{
    /**
     * Xử lý khi tài xế gửi checkpoint 'end' (kết thúc đơn hàng).
     *
     * Nếu tất cả orders trong trip đã Completed → tự động kết thúc chuyến,
     * ghi nhận km_end và cập nhật vehicle mileage.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(Trip $trip, array $payload): void
    {
        $endKm = isset($payload['km_reading']) ? (float) $payload['km_reading'] : null;

        if ($endKm === null) {
            return;
        }

        if ($trip->isCompleted() || $trip->status === TripStatus::Cancelled) {
            return;
        }

        if ($trip->orders()->doesntExist()) {
            return;
        }

        $pendingOrders = $trip->orders()
            ->where('status', '!=', OrderStatus::Completed->value)
            ->exists();

        if ($pendingOrders) {
            return;
        }

        $trip->complete(endKm: $endKm);
    }
}
