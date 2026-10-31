<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Loại hình tính cước (sheet 3.x cột C). Giá trị là chuỗi lưu trong DB; "Chuẩn" lưu chuỗi rỗng.
 */
enum FreightRateType: string implements HasLabel
{
    use HasBillingTypeOptions;

    case Standard = '';
    case TwoDrops = '2 điểm trả';
    case ClcSdv = 'CLC/SDV';
    case Cold = 'Hàng lạnh';
    case Kioway1 = 'KIOWAY 1';
    case Kioway2 = 'KIOWAY 2';
    case Outside = 'NGOÀI';
    case NuiPhao = 'NÚI PHÁO';
}
