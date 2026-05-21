<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DriverSwapReason: string implements HasColor, HasLabel
{
    case ShiftHandover = 'shift_handover';
    case CargoNotUnloaded = 'cargo_not_unloaded';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::ShiftHandover => 'Bàn giao ca',
            self::CargoNotUnloaded => 'Hàng chưa hạ được',
            self::Other => 'Lý do khác',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ShiftHandover => 'info',
            self::CargoNotUnloaded => 'warning',
            self::Other => 'gray',
        };
    }
}
