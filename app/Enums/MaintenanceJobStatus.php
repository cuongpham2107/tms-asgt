<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MaintenanceJobStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Overdue = 'overdue';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Chờ thực hiện',
            self::InProgress => 'Đang thực hiện',
            self::Completed => 'Hoàn thành',
            self::Cancelled => 'Hủy',
            self::Overdue => 'Quá hạn',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::InProgress => 'info',
            self::Completed => 'success',
            self::Cancelled => 'danger',
            self::Overdue => 'danger',
        };
    }
}
