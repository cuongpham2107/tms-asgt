<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LocationType: string implements HasColor, HasLabel
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';
    case Warehouse = 'warehouse';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pickup => 'Điểm lấy hàng',
            self::Delivery => 'Điểm giao hàng',
            self::Warehouse => 'Kho hàng',
            self::Other => 'Khác',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pickup => 'info',
            self::Delivery => 'success',
            self::Warehouse => 'primary',
            self::Other => 'gray',
        };
    }
}
