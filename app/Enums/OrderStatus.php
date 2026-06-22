<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Assigned = 'assigned';
    case Sent = 'sent';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Assigned => 'Đã gán xe',
            self::Sent => 'Đã gửi',
            self::Completed => 'Hoàn thành',
            self::Cancelled => 'Hủy',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Assigned => 'info',
            self::Sent => 'info',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function canAssign(): bool
    {
        return $this === self::Draft;
    }

    public function canSend(): bool
    {
        return $this === self::Assigned;
    }

    public function canRecall(): bool
    {
        return $this === self::Sent;
    }

    public function canCancel(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled]);
    }

    public function canEdit(): bool
    {
        return in_array($this, [self::Draft, self::Assigned]);
    }

    public function canDelete(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled]);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled]);
    }

    public function canCreateReturn(): bool
    {
        return $this === self::Completed;
    }
}
