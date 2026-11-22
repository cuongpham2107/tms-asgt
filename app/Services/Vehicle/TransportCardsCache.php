<?php

namespace App\Services\Vehicle;

use Illuminate\Support\Facades\Cache;

/**
 * Cache danh sách thẻ xe / lái xe khi gán vận chuyển. Xoá khi đơn, chuyến, xe hoặc ca thay đổi.
 * Tách khỏi concern Filament để model và observer không phụ thuộc lớp giao diện.
 */
class TransportCardsCache
{
    public const VEHICLE_KEY = 'resolve-vehicle-cards-base';

    public const DRIVER_KEY = 'resolve-driver-cards';

    public static function clear(): void
    {
        Cache::forget(self::VEHICLE_KEY);
        Cache::forget(self::DRIVER_KEY);
    }
}
