<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VehicleType: string implements HasColor, HasLabel
{
    case Normal = 'normal';
    case Cold = 'cold';
    case AntiVibration = 'anti_vibration';
    case Container = 'container';
    case Flatbed = 'flatbed';
    case BatWing = 'bat_wing';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal => 'Xe tải thường',
            self::Cold => 'Xe tải lạnh',
            self::AntiVibration => 'Xe chống rung',
            self::Container => 'Xe container',
            self::Flatbed => 'Xe fooc',
            self::BatWing => 'Cánh dơi',
            self::Other => 'Khác',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Normal => 'gray',
            self::Cold => 'info',
            self::AntiVibration => 'warning',
            self::Container => 'primary',
            self::Flatbed => 'warning',
            self::BatWing => 'danger',
            self::Other => 'gray',
        };
    }
}
