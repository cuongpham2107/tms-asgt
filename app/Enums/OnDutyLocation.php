<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OnDutyLocation: string implements HasColor, HasLabel
{
    case Tn = 'TN';
    case Bn = 'BN';
    case Nba = 'NBA';

    public function getLabel(): string
    {
        return match ($this) {
            self::Tn => 'TN (Thái Nguyên)',
            self::Bn => 'BN (Bắc Ninh)',
            self::Nba => 'NBA (Nội Bài)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Tn => 'info',
            self::Bn => 'warning',
            self::Nba => 'success',
        };
    }
}
