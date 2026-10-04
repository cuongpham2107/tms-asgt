<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AssignmentEndReason: string implements HasColor, HasLabel
{
    case ShiftHandover = 'shift_handover';
    case CargoNotUnloaded = 'cargo_not_unloaded';
    case Reassigned = 'reassigned';
    case TripFinished = 'trip_finished';
    case TripCancelled = 'trip_cancelled';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::ShiftHandover => 'Bàn giao ca',
            self::CargoNotUnloaded => 'Hàng chưa hạ được',
            self::Reassigned => 'Điều hành đổi tài xế',
            self::TripFinished => 'Hoàn thành chuyến',
            self::TripCancelled => 'Chuyến bị huỷ',
            self::Other => 'Lý do khác',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ShiftHandover => 'info',
            self::CargoNotUnloaded, self::Reassigned => 'warning',
            self::TripFinished => 'success',
            self::TripCancelled => 'danger',
            self::Other => 'gray',
        };
    }

    /**
     * Lý do lái xe được chọn khi xin đảo lái từ app.
     *
     * @return array<int, self>
     */
    public static function driverSwapReasons(): array
    {
        return [self::ShiftHandover, self::CargoNotUnloaded, self::Other];
    }
}
