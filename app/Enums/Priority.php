<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum Priority: string implements HasColor, HasLabel
{
    case Urgent = 'urgent';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function getLabel(): string
    {
        return match ($this) {
            self::Urgent => 'Khẩn cấp',
            self::High => 'Ưu tiên cao',
            self::Medium => 'Trung bình',
            self::Low => 'Thấp',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Urgent => 'danger',
            self::High => 'warning',
            self::Medium => 'info',
            self::Low => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Urgent => 'heroicon-c-exclamation-triangle',
            self::High => 'heroicon-c-flag',
            self::Medium => 'heroicon-c-information-circle',
            self::Low => 'heroicon-c-arrow-down',
        };
    }
}
