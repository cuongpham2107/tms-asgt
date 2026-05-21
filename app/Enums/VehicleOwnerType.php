<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VehicleOwnerType: string implements HasColor, HasLabel
{
    case Company = 'company';
    case Rent = 'rent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Company => 'Xe công ty',
            self::Rent => 'Xe thuê ngoài',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Company => 'info',
            self::Rent => 'warning',
        };
    }
}
