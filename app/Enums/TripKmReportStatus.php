<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TripKmReportStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Chờ xử lý',
            self::Resolved => 'Đã xử lý',
            self::Rejected => 'Từ chối',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Resolved => 'success',
            self::Rejected => 'danger',
        };
    }
}
