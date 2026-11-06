<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cách tính một loại phí. Manual = kế toán nhập tay; các cách còn lại hệ thống tự tính khi tính cước.
 */
enum FeeCalcMethod: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case Fixed = 'fixed';
    case PerPallet = 'per_pallet';
    case PerPackage = 'per_package';
    case PerExtraDrop = 'per_extra_drop';
    case PercentOfBase = 'percent_of_base';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Nhập tay',
            self::Fixed => 'Cố định / đơn',
            self::PerPallet => 'Theo số pallet',
            self::PerPackage => 'Theo số thùng/kiện',
            self::PerExtraDrop => 'Theo mỗi điểm rớt',
            self::PercentOfBase => '% đơn giá chính',
        };
    }

    public function getColor(): string
    {
        return $this === self::Manual ? 'gray' : 'success';
    }

    public function isAuto(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * Đơn vị của ô "Số lượng" trong modal cước.
     */
    public function quantityLabel(): string
    {
        return match ($this) {
            self::PerPallet => 'pallet',
            self::PerPackage => 'thùng',
            self::PerExtraDrop => 'điểm',
            default => 'lần',
        };
    }

    /**
     * Đơn vị của ô "Số tiền" trên form.
     */
    public function unit(): string
    {
        return match ($this) {
            self::PercentOfBase => '%',
            self::PerPallet => 'đ / pallet',
            self::PerPackage => 'đ / thùng',
            self::PerExtraDrop => 'đ / điểm',
            default => 'đ',
        };
    }
}
