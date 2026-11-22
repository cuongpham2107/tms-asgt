<?php

namespace App\Services\Import;

use App\Enums\VehicleDocumentStatus;
use App\Enums\VehicleDocumentType;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Throwable;

/**
 * Import giấy tờ xe từ file theo mẫu:
 * A Biển số | B Loại giấy tờ | C Số chứng nhận | D Nơi cấp | E Ngày cấp | F Ngày hết hạn | G Phí gia hạn | H Gia hạn lần cuối | I Ghi chú.
 * Cùng xe + loại + số chứng nhận thì cập nhật, ngược lại tạo mới. Trạng thái tính theo ngày hết hạn.
 */
class VehicleDocumentImporter
{
    public const SHEET_NAME = 'Giấy tờ xe';

    public const HEADERS = [
        'Biển số xe (*)',
        'Loại giấy tờ (*)',
        'Số chứng nhận (*)',
        'Nơi cấp (*)',
        'Ngày cấp (*)',
        'Ngày hết hạn (*)',
        'Phí gia hạn (VNĐ)',
        'Gia hạn lần cuối',
        'Ghi chú',
    ];

    private const TEMPLATE_ROWS = 500;

    /**
     * @return array{created: int, updated: int, errors: list<string>}
     */
    public function import(string $path, int $userId): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        $sheet = $workbook->getSheetByName(self::SHEET_NAME) ?? $workbook->getSheet(0);

        $vehicleIdsByPlate = Vehicle::query()->pluck('id', 'plate_number')
            ->mapWithKeys(fn (int $id, string $plate): array => [self::normalizePlate($plate) => $id]);

        $created = 0;
        $updated = 0;
        $errors = [];
        $lastRow = $sheet->getHighestDataRow();

        DB::transaction(function () use ($sheet, $lastRow, $vehicleIdsByPlate, $userId, &$created, &$updated, &$errors): void {
            foreach ($sheet->rangeToArray('A2:I'.max(2, $lastRow), null, true, false) as $offset => $row) {
                $rowNumber = $offset + 2;

                if (collect($row)->every(fn ($value): bool => blank($value))) {
                    continue;
                }

                $parsed = $this->parseRow($row, $vehicleIdsByPlate->all());

                if (is_string($parsed)) {
                    $errors[] = "Dòng {$rowNumber}: {$parsed}";

                    continue;
                }

                $document = VehicleDocument::withTrashed()->firstOrNew([
                    'vehicle_id' => $parsed['vehicle_id'],
                    'doc_type' => $parsed['doc_type'],
                    'certificate_number' => $parsed['certificate_number'],
                ]);

                $isNew = ! $document->exists;
                $document->fill($parsed);
                $document->created_by ??= $userId;
                $document->deleted_at = null;
                $document->save();

                $isNew ? $created++ : $updated++;
            }
        });

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /**
     * File mẫu: sheet nhập liệu (có danh sách chọn loại giấy tờ) và sheet hướng dẫn kèm dòng ví dụ.
     */
    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet()->setTitle(self::SHEET_NAME);

        $sheet->fromArray(self::HEADERS, null, 'A1');
        $headerStyle = $sheet->getStyle('A1:I1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DBEAFE');
        $sheet->freezePane('A2');

        $lastRow = self::TEMPLATE_ROWS + 1;
        $sheet->getStyle("A2:D{$lastRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle("E2:F{$lastRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle("H2:H{$lastRow}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle("G2:G{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');

        $typeLabels = collect(VehicleDocumentType::cases())->map(fn (VehicleDocumentType $type): string => $type->getLabel())->implode(',');
        $validation = $sheet->getCell('B2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle('Loại giấy tờ không hợp lệ')
            ->setError('Chọn một loại trong danh sách.')
            ->setFormula1('"'.$typeLabels.'"');
        $validation->setSqref("B2:B{$lastRow}");

        foreach (['A' => 16, 'B' => 18, 'C' => 20, 'D' => 26, 'E' => 14, 'F' => 16, 'G' => 18, 'H' => 18, 'I' => 30] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $guide = $spreadsheet->createSheet()->setTitle('Hướng dẫn');
        $guide->fromArray([
            ['Hướng dẫn nhập giấy tờ xe'],
            ['- Nhập dữ liệu ở sheet "'.self::SHEET_NAME.'", mỗi dòng một giấy tờ. Cột có (*) là bắt buộc.'],
            ['- Biển số xe phải có trong danh sách Phương tiện (viết có hay không có dấu gạch, dấu chấm đều được).'],
            ['- Loại giấy tờ: '.str_replace(',', ' hoặc ', $typeLabels).'.'],
            ['- Ngày nhập theo dạng ngày/tháng/năm, ví dụ 15/12/2026.'],
            ['- Cùng xe + loại + số chứng nhận đã có thì sẽ được cập nhật, ngược lại tạo mới.'],
            ['- Trạng thái tự tính theo ngày hết hạn: quá hạn, sắp hết hạn (còn ≤ '.VehicleDocumentStatus::EXPIRING_SOON_DAYS.' ngày) hoặc còn hiệu lực.'],
            [''],
            ['Dòng ví dụ (không nhập ở sheet này):'],
        ], null, 'A1');
        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $guide->fromArray(self::HEADERS, null, 'A10');
        $guide->getStyle('A10:I10')->getFont()->setBold(true);
        $guide->fromArray(['29C-123.45', VehicleDocumentType::Inspection->getLabel(), 'KD-0123456', 'Trung tâm đăng kiểm 29-01V', '15/06/2026', '14/12/2026', '560000', '15/06/2026', 'Đăng kiểm 6 tháng'], null, 'A11');
        $guide->getColumnDimension('A')->setWidth(16);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $vehicleIdsByPlate
     * @return array<string, mixed>|string dữ liệu đã chuẩn hoá, hoặc thông báo lỗi
     */
    private function parseRow(array $row, array $vehicleIdsByPlate): array|string
    {
        $plate = trim((string) $row[0]);
        $vehicleId = $vehicleIdsByPlate[self::normalizePlate($plate)] ?? null;
        if ($vehicleId === null) {
            return $plate === '' ? 'thiếu biển số xe.' : "không tìm thấy xe {$plate}.";
        }

        $docType = self::docType($row[1]);
        if ($docType === null) {
            return 'loại giấy tờ "'.trim((string) $row[1]).'" không hợp lệ.';
        }

        $certificateNumber = trim((string) $row[2]);
        $issuedBy = trim((string) $row[3]);
        if ($certificateNumber === '' || $issuedBy === '') {
            return 'thiếu số chứng nhận hoặc nơi cấp.';
        }

        $issuedDate = self::date($row[4]);
        $expiryDate = self::date($row[5]);
        if ($issuedDate === null || $expiryDate === null) {
            return 'ngày cấp hoặc ngày hết hạn trống hay sai định dạng (ngày/tháng/năm).';
        }
        if ($expiryDate->lt($issuedDate)) {
            return 'ngày hết hạn trước ngày cấp.';
        }

        $lastRenewedDate = blank($row[7]) ? null : self::date($row[7]);
        if (! blank($row[7]) && $lastRenewedDate === null) {
            return 'ngày gia hạn lần cuối sai định dạng (ngày/tháng/năm).';
        }

        $renewalCost = blank($row[6]) ? null : self::number($row[6]);
        if (! blank($row[6]) && $renewalCost === null) {
            return 'phí gia hạn không phải là số.';
        }

        return [
            'vehicle_id' => $vehicleId,
            'doc_type' => $docType,
            'certificate_number' => $certificateNumber,
            'issued_by' => $issuedBy,
            'issued_date' => $issuedDate,
            'expiry_date' => $expiryDate,
            'renewal_cost' => $renewalCost === null ? null : (int) round($renewalCost),
            'last_renewed_date' => $lastRenewedDate,
            'notes' => blank($row[8]) ? null : trim((string) $row[8]),
            'status' => VehicleDocumentStatus::forExpiryDate($expiryDate),
        ];
    }

    public static function normalizePlate(string $plate): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($plate)));
    }

    private static function docType(mixed $value): ?VehicleDocumentType
    {
        $text = mb_strtolower(trim((string) $value));

        foreach (VehicleDocumentType::cases() as $type) {
            if ($text === $type->value || $text === mb_strtolower($type->getLabel())) {
                return $type;
            }
        }

        return match ($text) {
            'đăng ký', 'dang ky', 'dang ky xe' => VehicleDocumentType::Registration,
            'dang kiem' => VehicleDocumentType::Inspection,
            default => null,
        };
    }

    /**
     * Ô ngày Excel (số serial) hoặc chuỗi d/m/Y, d-m-Y, Y-m-d.
     */
    private static function date(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->startOfDay();
        }

        $text = trim((string) $value);

        foreach (['d/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'Y-m-d', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === $text) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = (string) preg_replace('/[^\d]/', '', (string) $value);

        return $text === '' ? null : (float) $text;
    }
}
