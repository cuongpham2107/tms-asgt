<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TripStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Started = 'started';
    case ArrivedPickup = 'arrived_pickup';
    case Delivering = 'delivering';
    case ArrivedDelivery = 'arrived_delivery';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case DriverSwap = 'driver_swap';
    case Cancelled = 'cancelled';
    case ReturnTrip = 'return_trip';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Chờ chạy',
            self::Started => 'Đã bắt đầu',
            self::ArrivedPickup => 'Đến lấy hàng',
            self::Delivering => 'Đang giao',
            self::ArrivedDelivery => 'Đến giao hàng',
            self::Delivered => 'Đã giao',
            self::Completed => 'Hoàn thành',
            self::DriverSwap => 'Đảo lái',
            self::Cancelled => 'Đã huỷ',
            self::ReturnTrip => 'Chuyến quay đầu',
        };
    }

    /**
     * Các trạng thái được coi là "đang chạy" — chuyến chưa kết thúc.
     * Dùng để kiểm tra tài xế có đang thực hiện chuyến khác không.
     *
     * @return self[]
     */
    public static function activeStatuses(): array
    {
        return [
            self::Pending,
            self::Started,
            self::ArrivedPickup,
            self::Delivering,
            self::ArrivedDelivery,
            self::Delivered,
            self::DriverSwap,
            self::ReturnTrip,
        ];
    }

    /** Alias của getLabel() — dùng trong message lỗi API. */
    public function label(): string
    {
        return $this->getLabel();
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Started => 'info',
            self::ArrivedPickup => 'warning',
            self::Delivering => 'warning',
            self::ArrivedDelivery => 'warning',
            self::Delivered => 'success',
            self::Completed => 'success',
            self::DriverSwap => 'primary',
            self::Cancelled => 'danger',
            self::ReturnTrip => 'warning',
        };
    }

    public function canSwapDriver(): bool
    {
        return in_array($this, [
            self::Started,
            self::ArrivedPickup,
            self::Delivering,
            self::ArrivedDelivery,
        ]);
    }
}
