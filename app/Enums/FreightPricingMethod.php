<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FreightPricingMethod: string implements HasColor, HasLabel
{
    case ByVehicle = 'by_vehicle';
    case ByWeightRange = 'by_weight_range';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::ByVehicle => 'Theo tải trọng xe',
            self::ByWeightRange => 'Theo khoảng trọng tải hàng',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ByVehicle => 'gray',
            self::ByWeightRange => 'info',
        };
    }
}
