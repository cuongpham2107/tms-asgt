<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CheckpointType: string implements HasColor, HasLabel
{
    case Started = 'started';
    case ArrivedPickup = 'arrived_pickup';
    case LeftPickup = 'left_pickup';
    case ArrivedDelivery = 'arrived_delivery';
    case Completed = 'completed';
    case DriverSwap = 'driver_swap';

    public function getLabel(): string
    {
        return match ($this) {
            self::Started => 'Bắt đầu chuyến',
            self::ArrivedPickup => 'Đến lấy hàng',
            self::LeftPickup => 'Rời lấy hàng',
            self::ArrivedDelivery => 'Đến giao hàng',
            self::Completed => 'Hoàn thành',
            self::DriverSwap => 'Đảo lái',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Started => 'info',
            self::ArrivedPickup => 'warning',
            self::LeftPickup => 'warning',
            self::ArrivedDelivery => 'warning',
            self::Completed => 'success',
            self::DriverSwap => 'primary',
        };
    }
}
