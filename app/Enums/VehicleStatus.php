<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VehicleStatus: string implements HasColor, HasLabel
{
    case On = 'on';
    case Off = 'off';
    case Bdsc = 'bdsc';
    case Running = 'running';

    public function getLabel(): string
    {
        return match ($this) {
            self::On => 'Sẵn sàng',
            self::Off => 'Tắt',
            self::Bdsc => 'Bảo dưỡng sửa chữa',
            self::Running => 'Đang chạy',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::On => 'success',
            self::Off => 'danger',
            self::Bdsc => 'warning',
            self::Running => 'info',
        };
    }
}
