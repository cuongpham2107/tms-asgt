<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderDeliveryPointStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Arrived = 'arrived';
    case Delivered = 'delivered';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Chờ giao',
            self::Arrived => 'Đã đến',
            self::Delivered => 'Đã giao',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Arrived => 'info',
            self::Delivered => 'success',
        };
    }
}
