<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MaintenanceJobType: string implements HasColor, HasLabel
{
    case PeriodicMaintenance = 'periodic_maintenance';
    case Repair = 'repair';
    case Inspection = 'inspection';
    case Registration = 'registration';
    case Insurance = 'insurance';

    public function getLabel(): string
    {
        return match ($this) {
            self::PeriodicMaintenance => 'Bảo dưỡng định kỳ',
            self::Repair => 'Sửa chữa',
            self::Inspection => 'Kiểm tra',
            self::Registration => 'Đăng ký',
            self::Insurance => 'Bảo hiểm',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PeriodicMaintenance => 'info',
            self::Repair => 'warning',
            self::Inspection => 'warning',
            self::Registration => 'primary',
            self::Insurance => 'success',
        };
    }
}
