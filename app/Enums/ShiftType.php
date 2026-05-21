<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ShiftType: string implements HasColor, HasLabel
{
    case Full = 'full';
    case MorningHalf = 'morning_half';
    case NightHalf = 'night_half';

    public function getLabel(): string
    {
        return match ($this) {
            self::Full => 'Cả ca',
            self::MorningHalf => 'Nửa ca ngày',
            self::NightHalf => 'Nửa ca đêm',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Full => 'info',
            self::MorningHalf => 'warning',
            self::NightHalf => 'primary',
        };
    }
}
