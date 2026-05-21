<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VehicleDocumentType: string implements HasColor, HasLabel
{
    case Registration = 'registration';
    case Inspection = 'inspection';

    public function getLabel(): string
    {
        return match ($this) {
            self::Registration => 'Đăng ký xe',
            self::Inspection => 'Đăng kiểm',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Registration => 'info',
            self::Inspection => 'warning',
        };
    }
}
