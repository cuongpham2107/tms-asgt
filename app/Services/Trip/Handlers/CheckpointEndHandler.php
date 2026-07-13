<?php

namespace App\Services\Trip\Handlers;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use Illuminate\Support\Collection;

class CheckpointEndHandler implements CheckpointHandlerInterface
{
    /**
     * Xử lý khi tài xế gửi checkpoint 'end' (kết thúc đơn hàng).
     *
     * Nếu tất cả orders trong trip đã Completed và cùng điểm đến cuối
     * (hoặc trip có đúng 1 order) → tự động kết thúc chuyến,
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

        $orderIds = $trip->orders()->pluck('id');

        if ($orderIds->isEmpty()) {
            return;
        }

        $pendingOrders = $trip->orders()
            ->where('status', '!=', OrderStatus::Completed->value)
            ->exists();

        if ($pendingOrders) {
            return;
        }

        if (! $this->canAutoComplete($orderIds)) {
            return;
        }

        $trip->complete(endKm: $endKm);
    }

    /**
     * Kiểm tra điều kiện tự động kết thúc chuyến:
     * - Trip có đúng 1 order, hoặc
     * - Tất cả orders có cùng điểm đến cuối (cùng location_id của delivery point cuối)
     *
     * @param  Collection<int, int>  $orderIds
     */
    private function canAutoComplete($orderIds): bool
    {
        if ($orderIds->count() === 1) {
            return true;
        }

        $lastPointLocationIds = OrderDeliveryPoint::whereIn('order_id', $orderIds)
            ->orderBy('sequence', 'desc')
            ->get()
            ->groupBy('order_id')
            ->map(fn ($points) => $points->first()?->location_id);

        $uniqueLocationIds = $lastPointLocationIds->filter()->unique();

        return $uniqueLocationIds->count() === 1;
    }
}
