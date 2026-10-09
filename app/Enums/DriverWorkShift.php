<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DriverWorkShift: string implements HasColor, HasLabel
{
    case Even = 'even';
    case Odd = 'odd';

    public function getLabel(): string
    {
        return match ($this) {
            self::Even => 'Ca chẵn',
            self::Odd => 'Ca lẻ',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Even => 'info',
            self::Odd => 'warning',
        };
    }
}
