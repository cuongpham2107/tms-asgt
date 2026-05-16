<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Assigned = 'assigned';
    case Sent = 'sent';
    case Started = 'started';
    case ArrivedPickup = 'arrived_pickup';
    case Delivering = 'delivering';
    case ArrivedDelivery = 'arrived_delivery';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case DriverSwap = 'driver_swap';
    case Cancelled = 'cancelled';
    case Trashed = 'trashed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Assigned => 'Đã gán xe',
            self::Sent => 'Đã gửi',
            self::Started => 'Bắt đầu',
            self::ArrivedPickup => 'Đến lấy hàng',
            self::Delivering => 'Đang giao hàng',
            self::ArrivedDelivery => 'Đến giao hàng',
            self::Delivered => 'Đã giao hàng',
            self::Completed => 'Hoàn thành',
            self::DriverSwap => 'Đảo lái',
            self::Cancelled => 'Hủy',
            self::Trashed => 'Thùng rác',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Assigned => 'info',
            self::Sent => 'info',
            self::Started => 'info',
            self::ArrivedPickup => 'warning',
            self::Delivering => 'warning',
            self::ArrivedDelivery => 'warning',
            self::Delivered => 'success',
            self::Completed => 'success',
            self::DriverSwap => 'primary',
            self::Cancelled => 'danger',
            self::Trashed => 'gray',
        };
    }

    /** Có thể gán xe/lái: trước khi gửi lệnh */
    public function canAssign(): bool
    {
        return in_array($this, [self::Draft, self::Assigned]);
    }

    /** Có thể gửi lệnh cho lái xe */
    public function canSend(): bool
    {
        return $this === self::Assigned;
    }

    /** Có thể thu hồi lệnh đã gửi */
    public function canRecall(): bool
    {
        return in_array($this, [self::Sent, self::Started, self::ArrivedPickup]);
    }

    /** Có thể hủy đơn */
    public function canCancel(): bool
    {
        return ! in_array($this, [self::ArrivedDelivery, self::Delivered, self::Completed, self::Cancelled, self::Trashed]);
    }

    /** Có thể sửa đơn */
    public function canEdit(): bool
    {
        return in_array($this, [self::Draft, self::Assigned]);
    }

    /** Có thể xóa (soft delete) */
    public function canDelete(): bool
    {
        return in_array($this, [self::Draft, self::Cancelled]);
    }

    /** Có thể tạo chuyến quay đầu */
    public function canCreateReturn(): bool
    {
        return in_array($this, [self::Sent, self::Started, self::ArrivedPickup, self::Delivering]);
    }

    /** Có thể đảo lái */
    public function canSwapDriver(): bool
    {
        return in_array($this, [self::Started, self::ArrivedPickup, self::Delivering, self::ArrivedDelivery]);
    }

    /** Đơn đang hoạt động (đang chạy) */
    public function isActive(): bool
    {
        return in_array($this, [self::Started, self::ArrivedPickup, self::Delivering, self::ArrivedDelivery]);
    }

    /** Đơn đã kết thúc (thành công hoặc hủy) */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Trashed]);
    }
}
