<?php

namespace App\Services\Km;

use App\Models\Order;
use App\Models\TripCheckpoint;
use Illuminate\Support\Collection;

/**
 * Km có hàng của từng đơn = km mốc Hoàn thành lớn nhất − km mốc Đến lấy hàng nhỏ nhất.
 * Dùng chung cho tính km chuyến, km ca và lúc giao xong đơn.
 */
class OrderLoadedKm
{
    /**
     * @param  Collection<int, TripCheckpoint>  $checkpoints  mốc arrived_pickup / completed có order_id và km
     * @return array<int, float> order_id => loaded km (chỉ đơn có đủ cả hai mốc)
     */
    public static function fromCheckpoints(Collection $checkpoints): array
    {
        $result = [];

        foreach ($checkpoints->groupBy('order_id') as $orderId => $orderCheckpoints) {
            $pickupKm = $orderCheckpoints->filter(fn (TripCheckpoint $cp): bool => $cp->getRawOriginal('checkpoint_type') === 'arrived_pickup')->min('km_reading');
            $completedKm = $orderCheckpoints->filter(fn (TripCheckpoint $cp): bool => $cp->getRawOriginal('checkpoint_type') === 'completed')->max('km_reading');

            if ($pickupKm !== null && $completedKm !== null) {
                $result[(int) $orderId] = max(0, (float) $completedKm - (float) $pickupKm);
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     */
    public static function record(Collection $checkpoints): void
    {
        foreach (self::fromCheckpoints($checkpoints) as $orderId => $loadedKm) {
            Order::query()->find($orderId)?->update(['loaded_km' => $loadedKm]);
        }
    }
}
