<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VehicleDocumentStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';

    /** Số ngày trước hạn được coi là sắp hết hạn (khớp widget cảnh báo). */
    public const EXPIRING_SOON_DAYS = 30;

    public static function forExpiryDate(CarbonInterface $expiryDate, ?CarbonInterface $today = null): self
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $expiry = $expiryDate->copy()->startOfDay();

        return match (true) {
            $expiry->lt($today) => self::Expired,
            $expiry->lte($today->copy()->addDays(self::EXPIRING_SOON_DAYS)) => self::ExpiringSoon,
            default => self::Active,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Còn hiệu lực',
            self::ExpiringSoon => 'Sắp hết hạn',
            self::Expired => 'Đã hết hạn',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'success',
            self::ExpiringSoon => 'warning',
            self::Expired => 'danger',
        };
    }
}
