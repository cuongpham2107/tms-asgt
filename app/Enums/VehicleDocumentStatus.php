<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VehicleDocumentStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Còn hiệu lực',
            self::ExpiringSoon => 'Sắp hết hạn',
            self::Expired => 'Đã hết hạn',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'success',
            self::ExpiringSoon => 'warning',
            self::Expired => 'danger',
        };
    }
}
