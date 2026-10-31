<?php

namespace App\Services\Import;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Cập nhật PCS / GW cho đơn hàng từ file theo mẫu sheet "1.1 Mẫu Upload dữ liệu cân kiện":
 * A Loại hình phục vụ | B Mã đơn hàng | C Ngày | D BSX | E Hành trình | F PCS | G GW.
 * Ô PCS / GW trống thì giữ nguyên giá trị cũ của đơn.
 */
class OrderPackagesImporter
{
    private const SHEET_NAME = '1.1 Mẫu Upload dữ liệu cân kiện';

    /**
     * @return array{updated: int, unchanged: int, not_found: list<string>}
     */
    public function import(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        $sheet = $workbook->getSheetByName(self::SHEET_NAME) ?? $workbook->getSheet(0);

        $updated = 0;
        $unchanged = 0;
        $notFound = [];

        DB::transaction(function () use ($sheet, &$updated, &$unchanged, &$notFound): void {
            foreach ($sheet->rangeToArray('A2:G'.$sheet->getHighestDataRow(), null, true, false) as $row) {
                $code = trim((string) $row[1]);
                if ($code === '' || mb_strtolower($code) === 'mã đơn hàng') {
                    continue;
                }

                $order = Order::firstWhere('order_code', $code);
                if ($order === null) {
                    $notFound[] = $code;

                    continue;
                }

                $packages = self::number($row[5]);
                $weight = self::number($row[6]);

                $order->fill(array_filter([
                    'total_packages' => $packages === null ? null : (int) round($packages),
                    'total_weight' => $weight === null ? null : round($weight, 2),
                ], fn ($value): bool => $value !== null));

                if ($order->isDirty()) {
                    $order->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            }
        });

        return ['updated' => $updated, 'unchanged' => $unchanged, 'not_found' => array_values(array_unique($notFound))];
    }

    /**
     * "1.234,5" / "1,234.5" / "1,5" / "12 kiện" → số; ô trống → null.
     */
    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = preg_replace('/[^\d.,]/', '', (string) $value);
        if ($text === '') {
            return null;
        }

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        $text = match (true) {
            // "1.234,5" / "1,5": dấu phẩy là phần thập phân
            $lastComma !== false && ($lastDot === false || $lastComma > $lastDot) => str_replace(['.', ','], ['', '.'], $text),
            // "1,234.5": dấu phẩy là phân cách hàng nghìn
            default => str_replace(',', '', $text),
        };

        return is_numeric($text) ? (float) $text : null;
    }
}
