<?php

namespace App\Services\Km;

use App\Models\Order;
use App\Models\TripCheckpoint;
use Illuminate\Support\Collection;

/**
 * Km có hàng của từng đơn = km mốc Hoàn thành lớn nhất − km mốc Đến lấy hàng ĐẦU TIÊN theo thứ tự truyền vào.
 * Nơi gọi quyết định thứ tự: tính km chuyến / ca truyền mốc sắp theo km, lúc giao xong đơn truyền theo thứ tự tạo.
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
            $pickupKm = $orderCheckpoints->first(fn (TripCheckpoint $cp): bool => $cp->getRawOriginal('checkpoint_type') === 'arrived_pickup')?->km_reading;
            $completedKm = $orderCheckpoints->filter(fn (TripCheckpoint $cp): bool => $cp->getRawOriginal('checkpoint_type') === 'completed')->max('km_reading');

            if ($pickupKm !== null && $completedKm !== null) {
                $result[(int) $orderId] = max(0, (float) $completedKm - (float) $pickupKm);
            }
        }

        return $result;
    }

    /**
     * Ghi thẳng vào DB (không qua model event), giữ nguyên cách ghi cũ của tính km chuyến / ca.
     *
     * @param  Collection<int, TripCheckpoint>  $checkpoints
     */
    public static function record(Collection $checkpoints): void
    {
        foreach (self::fromCheckpoints($checkpoints) as $orderId => $loadedKm) {
            Order::query()->where('id', $orderId)->update(['loaded_km' => $loadedKm]);
        }
    }
}
