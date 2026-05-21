<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MaintenanceTriggerType: string implements HasColor, HasLabel
{
    case ByKm = 'by_km';
    case ByDate = 'by_date';
    case Both = 'both';

    public function getLabel(): string
    {
        return match ($this) {
            self::ByKm => 'Theo Km',
            self::ByDate => 'Theo ngày',
            self::Both => 'Cả hai',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ByKm => 'info',
            self::ByDate => 'warning',
            self::Both => 'primary',
        };
    }
}
