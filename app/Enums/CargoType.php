<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CargoType: string implements HasColor, HasLabel
{
    case Gcr = 'GCR';
    case Dangerous = 'DGR';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gcr => 'Hàng thường (GCR)',
            self::Dangerous => 'Hàng nguy hiểm (DGR)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Gcr => 'success',
            self::Dangerous => 'danger',
        };
    }
}
