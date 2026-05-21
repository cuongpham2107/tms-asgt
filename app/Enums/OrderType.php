<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderType: string implements HasColor, HasLabel
{
    case Hhhk = 'HHHK';
    case External = 'external';

    public function getLabel(): string
    {
        return match ($this) {
            self::Hhhk => 'Hàng hóa hàng không (HHHK)',
            self::External => 'Hàng ngoài (HN)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Hhhk => 'primary',
            self::External => 'success',
        };
    }
}
