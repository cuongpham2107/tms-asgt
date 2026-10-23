<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GeoCheckStatus: string implements HasColor, HasLabel
{
    case Valid = 'valid';
    case OutOfRange = 'out_of_range';
    case Unverified = 'unverified';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Valid => 'Đúng vị trí',
            self::OutOfRange => 'Sai vị trí',
            self::Unverified => 'Không xác minh được',
            self::Skipped => 'Không kiểm tra',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Valid => 'success',
            self::OutOfRange => 'danger',
            self::Unverified => 'gray',
            self::Skipped => 'gray',
        };
    }
}
