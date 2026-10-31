<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Loại hình tính phụ phí giờ chờ (sheet 4 cột B). Giá trị là chuỗi lưu trong DB; "Chuẩn" lưu chuỗi rỗng.
 */
enum SurchargeType: string implements HasLabel
{
    use HasBillingTypeOptions;

    case Standard = '';
    case AsglTienSon = 'ASGL TIÊN SƠN';
    case ClcSdv = 'CLC/SDV';
    case OldTripOrAsglHaiPhong = 'Chuyến cũ or ASGL HẢI PHÒNG';
    case Cold = 'Hàng lạnh';
    case IndustrialZoneNewTrip = 'KCN và chuyến mới phát sinh';
    case Kioway1 = 'KIOWAY 1';
    case Kioway2 = 'KIOWAY 2';
    case NuiPhao = 'NÚI PHÁO';
}
