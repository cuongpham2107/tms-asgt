<?php

namespace App\Services\Export\BillingStatement;

use App\Enums\CheckpointType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderChargeFee;
use App\Models\OrderDeliveryPoint;
use App\Services\Billing\OrderChargeCalculator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Xuất bảng kê đối chiếu vận chuyển cho 1 khách theo mẫu Excel của khách (BillingStatementTemplates).
 * Ghi số đã chốt trong order_charges (không tính lại tiền); chỉ dòng Tổng dùng công thức SUM.
 */
class BillingStatementExporter
{
    private const COMPANY_LINES = [
        'CÔNG TY TNHH VẬN TẢI ASG',
        'Địa chỉ:  Lô số 5, Khu Công nghiệp Yên Bình, Phường Vạn Xuân, Tỉnh Thái Nguyên',
        'MST: 4601547193',
    ];

    private const COMPANY_NAME = 'CÔNG TY TNHH VẬN TẢI ASG';

    private const SIGN_PLACE = 'Thái Nguyên';

    private const MONEY_KEYS = ['base_price', 'adjusted_price', 'waiting_fee', 'fee_rest', 'subtotal', 'vat', 'total'];

    private const FORMAT_MONEY = '#,##0';

    /** Màu nền tiêu đề cột như các file mẫu. */
    private const HEADER_FILL = 'FF92D050';

    private const HEADER_ROW_HEIGHT = 50;

    /** Chiều cao 1 dòng chữ (pt) ở cỡ chữ 11. */
    private const LINE_HEIGHT = 15;

    private const FORMAT_DATE = 'dd/mm/yyyy';

    private const FORMAT_TIME = 'hh:mm';

    private const FORMAT_DURATION = '[h]:mm';

    public function __construct(private readonly OrderChargeCalculator $calculator) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  Dòng đang hiển thị trên trang Bảng kê (đã lọc)
     * @param  array{title?: ?string, sign_date?: mixed, invoice_symbol?: ?string, invoice_number?: ?string, invoice_date?: mixed}  $options
     * @return array{spreadsheet: Spreadsheet, order_ids: list<int>, unmapped_fees: list<string>}
     */
    public function build(Collection $rows, Customer $customer, string $templateKey, array $options = []): array
    {
        $template = BillingStatementTemplates::all()[$templateKey] ?? throw new InvalidArgumentException("Không có mẫu bảng kê {$templateKey}");

        $rowsByOrder = $rows->filter(fn (array $row): bool => filled($row['order_id'] ?? null))->keyBy('order_id');
        $orders = Order::query()
            ->with(['charge.fees.feeType', 'pickupLocation', 'deliveryPoints.location.billingZone', 'trip.vehicle', 'trip.checkpoints', 'tripCheckpoints'])
            ->whereIn('id', $rowsByOrder->keys())
            ->where('customer_id', $customer->id)
            ->get()
            ->sortBy(fn (Order $order): array => [
                (string) ($rowsByOrder[$order->id]['date'] ?? ''),
                (string) ($rowsByOrder[$order->id]['plate_number'] ?? ''),
            ])
            ->values();

        $columns = $template['columns'];
        $mappedFeeCodes = collect($columns)
            ->filter(fn (array $column): bool => str_starts_with($column[0], 'fee:'))
            ->flatMap(fn (array $column): array => explode(',', substr($column[0], 4)))
            ->all();
        $hasRestColumn = collect($columns)->contains(fn (array $column): bool => $column[0] === 'fee_rest');

        $unmappedFees = [];
        $perDrop = ($template['layout'] ?? 'single') === 'per_drop';
        $dataRows = $orders->flatMap(function (Order $order, int $index) use ($rowsByOrder, $mappedFeeCodes, $hasRestColumn, $perDrop, &$unmappedFees): array {
            $values = $this->rowValues($order, $rowsByOrder[$order->id], $index + 1, $mappedFeeCodes, $hasRestColumn);
            array_push($unmappedFees, ...$values['_unmapped']);

            return $perDrop ? $this->perDropRows($order, $values) : [$values];
        })->values();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('BẢNG KÊ');

        $this->writeDocument($sheet, $template, $customer, $dataRows, $orders, $options);

        return [
            'spreadsheet' => $spreadsheet,
            'order_ids' => $orders->pluck('id')->all(),
            'unmapped_fees' => array_values(array_unique($unmappedFees)),
        ];
    }

    /**
     * Giá trị từng khoá cột cho 1 đơn.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $mappedFeeCodes
     * @return array<string, mixed>
     */
    private function rowValues(Order $order, array $row, int $stt, array $mappedFeeCodes, bool $hasRestColumn): array
    {
        $charge = $order->charge;
        $fees = $charge?->fees ?? collect();
        $deliveryPoints = $order->deliveryPoints->sortBy('sequence')->values();
        $dropLocations = $deliveryPoints->map(fn ($point): ?Location => $point->location);

        $timeLoading = $this->carbon($row['time_a'] ?? null);
        $timeUnloaded = $this->carbon($row['time_d'] ?? null);

        $restFees = $fees->reject(fn (OrderChargeFee $fee): bool => in_array($fee->feeType?->code, $mappedFeeCodes, true));
        $unmapped = $hasRestColumn ? [] : $restFees->map(fn (OrderChargeFee $fee): string => (string) $fee->feeType?->name)->all();

        $notes = collect([$charge?->note ?: $order->notes])
            ->merge($fees->filter(fn (OrderChargeFee $fee): bool => filled($fee->note))
                ->map(fn (OrderChargeFee $fee): string => "{$fee->feeType?->name}: {$fee->note}"))
            ->merge($hasRestColumn ? [] : $restFees->map(fn (OrderChargeFee $fee): string => $fee->feeType?->name.': '.number_format((float) $fee->amount, 0, ',', '.').' đ'))
            ->filter()
            ->implode('; ');

        $waits = $charge === null ? null : $this->calculator->previewWaiting(
            $order,
            $charge,
            (int) ($row['time_loading'] ?? 0),
            $deliveryPoints->count() > 1 ? null : (int) ($row['time_unloading'] ?? 0),
        );

        return [
            'stt' => $stt,
            'date' => $this->carbon($row['date'] ?? null),
            'plate' => $row['plate_number'] ?? $order->trip?->vehicle?->plate_number,
            'pickup' => $this->address($order->pickupLocation, $order->pickup_address),
            'pickup_name' => $order->pickupLocation?->name,
            'drop' => $this->numbered($deliveryPoints->map(fn (OrderDeliveryPoint $point): string => $this->address($point->location, $point->address))),
            'drop_name' => $this->numbered($dropLocations->map(fn (?Location $location): ?string => $location?->name)),
            'drop_old_address' => $dropLocations->map(fn (?Location $location): ?string => $location?->old_address)->filter()->implode(' / '),
            'drop_zone' => $dropLocations->map(fn (?Location $location): ?string => $location?->billingZone?->name)->filter()->unique()->implode(' / '),
            'tonnage' => $charge?->chargeable_tonnage === null ? $order->chargeable_weight : (float) $charge->chargeable_tonnage,
            'pallet' => $order->pallet_count,
            'packages' => $order->total_packages,
            'bill' => $order->bill_number,
            'vehicle_owner' => $order->trip?->vehicle?->owner,
            'time_required' => $order->planned_loading_at,
            'delivery_note_numbers' => collect($charge?->delivery_notes ?? [])->pluck('number')->filter()->implode(' '),
            'chargeable_weight_kg' => $this->sumWeights($charge?->delivery_notes, 'chargeable_weight'),
            'actual_weight_kg' => $this->sumWeights($charge?->delivery_notes, 'actual_weight'),
            'time_loading' => $timeLoading,
            'time_departed' => $this->carbon($row['time_b'] ?? null),
            'time_arrived' => $this->carbon($row['time_c'] ?? null),
            'time_unloaded' => $timeUnloaded,
            'unload_date' => $timeUnloaded?->copy()->startOfDay(),
            'duration_loading' => $row['time_loading'] ?? null,
            'duration_travel' => $row['time_travel'] ?? null,
            'duration_unloading' => $row['time_unloading'] ?? null,
            'wait_loading' => $waits['billable_loading'] ?? null,
            'wait_unloading' => $waits['billable_unloading'] ?? null,
            'wait_total' => $charge?->waiting_minutes,
            'base_price' => $charge?->base_price === null ? null : (float) $charge->base_price,
            'adjusted_price' => $charge?->adjusted_price === null ? null : (float) $charge->adjusted_price,
            'waiting_fee' => $charge === null ? null : (float) $charge->waiting_fee,
            'fee_rest' => $hasRestColumn ? (float) $restFees->sum('amount') : null,
            '_fees' => $fees->groupBy(fn (OrderChargeFee $fee): string => (string) $fee->feeType?->code)
                ->map(fn (Collection $group): float => (float) $group->sum('amount'))
                ->all(),
            'subtotal' => $charge === null ? null : (float) $charge->subtotal,
            'vat' => $charge === null ? null : (float) $charge->vat_amount,
            '_vat_percent' => $charge === null ? null : (float) $charge->vat_percent,
            'total' => $charge === null ? null : (float) $charge->total,
            'note' => $notes,
            '_unmapped' => $unmapped,
            '_free_minutes' => (int) ($waits['tier']['free_minutes'] ?? 0),
        ];
    }

    /**
     * Bố cục "mỗi điểm trả 1 dòng" (SAFO rớt điểm): cùng STT, dòng đầu đủ thông tin chuyến và tiền,
     * các dòng sau chỉ có điểm trả, giờ đến/hạ hàng và thời gian chờ hạ của điểm đó.
     *
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function perDropRows(Order $order, array $values): array
    {
        $points = $order->deliveryPoints->sortBy('sequence')->values();
        if ($points->count() < 2) {
            return [$values];
        }

        $checkpoints = $order->tripCheckpoints->isNotEmpty() ? $order->tripCheckpoints : ($order->trip?->checkpoints ?? collect());

        return $points->map(function (OrderDeliveryPoint $point, int $index) use ($values, $checkpoints): array {
            $pointCheckpoints = $checkpoints->where('delivery_point_id', $point->id);
            $arrived = $this->carbon($pointCheckpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedDelivery)?->occurred_at ?? $point->arrived_at);
            $unloaded = $this->carbon($pointCheckpoints->where('checkpoint_type', CheckpointType::Completed)->last()?->occurred_at ?? $point->delivered_at);
            $unloading = ($arrived && $unloaded) ? max(0, (int) $arrived->diffInMinutes($unloaded)) : null;

            $pointValues = [
                'stt' => $values['stt'],
                'date' => $values['date'],
                'drop' => $this->address($point->location, $point->address),
                'drop_name' => $point->location?->name,
                'drop_old_address' => $point->location?->old_address,
                'drop_zone' => $point->location?->billingZone?->name,
                'time_arrived' => $arrived,
                'time_unloaded' => $unloaded,
                'unload_date' => $unloaded?->copy()->startOfDay(),
                'duration_unloading' => $unloading,
                'wait_unloading' => $unloading === null ? null : max(0, $unloading - $values['_free_minutes']),
            ];

            return $index === 0 ? [...$values, ...$pointValues] : $pointValues;
        })->all();
    }

    /**
     * Nhiều điểm thì đánh số "Điểm 1: … / Điểm 2: …" như mẫu VFC; 1 điểm giữ nguyên.
     *
     * @param  Collection<int, ?string>  $values
     */
    private function numbered(Collection $values): string
    {
        $values = $values->filter()->values();

        return $values->count() < 2
            ? (string) $values->first()
            : $values->map(fn (string $value, int $index): string => 'Điểm '.($index + 1).': '.$value)->implode(' / ');
    }

    /**
     * @param  ?array<int, array{number?: ?string, actual_weight?: mixed, chargeable_weight?: mixed}>  $notes
     */
    private function sumWeights(?array $notes, string $key): ?float
    {
        $weights = collect($notes ?? [])->pluck($key)->filter(fn (mixed $weight): bool => is_numeric($weight));

        return $weights->isEmpty() ? null : (float) $weights->sum();
    }

    /**
     * @param  array{title: string, customer_label: string, columns: list<array{0: string, 1: string, 2: ?string}>}  $template
     * @param  Collection<int, array<string, mixed>>  $dataRows
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $options
     */
    private function writeDocument(Worksheet $sheet, array $template, Customer $customer, Collection $dataRows, Collection $orders, array $options): void
    {
        $columns = $template['columns'];
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(11);

        foreach (self::COMPANY_LINES as $index => $line) {
            $sheet->setCellValue('B'.($index + 1), $line);
        }
        $sheet->getStyle('B1')->getFont()->setBold(true);

        $sheet->setCellValue('A5', $options['title'] ?? $template['title']);
        $sheet->mergeCells("A5:{$lastColumn}5");
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(18);
        $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $invoice = $this->invoiceLine($options);
        if ($invoice !== null) {
            $sheet->setCellValue('A6', $invoice);
            $sheet->mergeCells("A6:{$lastColumn}6");
            $sheet->getStyle('A6')->getFont()->setItalic(true);
            $sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $sheet->setCellValue('B7', "{$template['customer_label']}: {$customer->name}");
        $sheet->setCellValue('B8', 'Địa chỉ: '.($customer->address ?? ''));
        $sheet->setCellValue('B9', 'Mã số thuế: '.($customer->tax_code ?? ''));
        $sheet->getStyle('B7')->getFont()->setBold(true);

        $headerRow = 11;
        $this->writeHeader($sheet, $columns, $headerRow, $this->vatLabel($dataRows));

        $firstDataRow = $headerRow + 2;
        foreach ($dataRows->values() as $index => $values) {
            $this->writeDataRow($sheet, $columns, $firstDataRow + $index, $values);
        }

        $lastDataRow = $firstDataRow + max($dataRows->count(), 1) - 1;
        $totalRow = $lastDataRow + 1;
        $this->writeTotalRow($sheet, $columns, $totalRow, $firstDataRow, $lastDataRow);

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$totalRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        $grandTotal = (float) $dataRows->sum(fn (array $values): float => (float) ($values['total'] ?? 0));
        $sheet->setCellValue('B'.($totalRow + 2), 'Tổng số tiền bằng chữ : '.VietnameseMoneyWords::convert($grandTotal).'./.');
        $sheet->getStyle('B'.($totalRow + 2))->getFont()->setBold(true)->setItalic(true);

        $signDate = $this->carbon($options['sign_date'] ?? null) ?? now();
        $rightColumn = Coordinate::stringFromColumnIndex(max(2, (int) ceil(count($columns) * 0.6)));
        $sheet->setCellValue($rightColumn.($totalRow + 4), self::SIGN_PLACE.', ngày '.$signDate->format('d').' tháng '.$signDate->format('m').' năm '.$signDate->format('Y'));
        $sheet->getStyle($rightColumn.($totalRow + 4))->getFont()->setItalic(true);
        $sheet->setCellValue('C'.($totalRow + 5), mb_strtoupper($customer->name));
        $sheet->setCellValue($rightColumn.($totalRow + 5), self::COMPANY_NAME);
        $sheet->getStyle('C'.($totalRow + 5))->getFont()->setBold(true);
        $sheet->getStyle($rightColumn.($totalRow + 5))->getFont()->setBold(true);

        $sheet->freezePane('A'.$firstDataRow);
    }

    /**
     * Header 2 tầng: gộp dọc ô không có tiêu đề con, gộp ngang tiêu đề nhóm.
     *
     * @param  list<array{0: string, 1: string, 2: ?string}>  $columns
     */
    private function writeHeader(Worksheet $sheet, array $columns, int $row, string $vatLabel): void
    {
        $groupStart = null;
        foreach ($columns as $index => [$key, $group, $sub]) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $group = str_replace('{vat}', $vatLabel, $group);

            if ($group !== '') {
                $this->closeGroup($sheet, $groupStart, $index, $row);
                $sheet->setCellValue("{$column}{$row}", $group);
                $groupStart = $sub === null ? null : $index;
            }

            if ($sub === null) {
                $sheet->mergeCells("{$column}{$row}:{$column}".($row + 1));
            } else {
                $sheet->setCellValue($column.($row + 1), $sub);
            }

            $sheet->getColumnDimension($column)->setWidth($this->columnWidth($key));
        }
        $this->closeGroup($sheet, $groupStart, count($columns), $row);

        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex(count($columns)).($row + 1);
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        // 2 dòng tiêu đề cao như file mẫu để chữ xuống dòng không bị cắt.
        $sheet->getRowDimension($row)->setRowHeight(self::HEADER_ROW_HEIGHT);
        $sheet->getRowDimension($row + 1)->setRowHeight(self::HEADER_ROW_HEIGHT);
    }

    private function closeGroup(Worksheet $sheet, ?int $groupStart, int $nextIndex, int $row): void
    {
        if ($groupStart === null || $nextIndex - $groupStart < 2) {
            return;
        }

        $sheet->mergeCells(Coordinate::stringFromColumnIndex($groupStart + 1).$row.':'.Coordinate::stringFromColumnIndex($nextIndex).$row);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: ?string}>  $columns
     * @param  array<string, mixed>  $values
     */
    private function writeDataRow(Worksheet $sheet, array $columns, int $row, array $values): void
    {
        $lines = 1;
        foreach ($columns as $index => [$key]) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).$row;
            $value = $this->valueFor($key, $values);

            if ($value === null || $value === '') {
                continue;
            }

            if ($value instanceof CarbonInterface) {
                $isTime = str_starts_with($key, 'time_');
                $sheet->setCellValue($cell, $isTime
                    ? ExcelDate::PHPToExcel($value) - floor(ExcelDate::PHPToExcel($value))
                    : floor(ExcelDate::PHPToExcel($value)));
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($isTime ? self::FORMAT_TIME : self::FORMAT_DATE);

                continue;
            }

            if (str_starts_with($key, 'duration_') || str_starts_with($key, 'wait_')) {
                $sheet->setCellValue($cell, max(0, (int) $value) / 1440);
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::FORMAT_DURATION);

                continue;
            }

            $sheet->setCellValue($cell, $value);
            if (is_string($value)) {
                $lines = max($lines, $this->wrappedLines($value, $this->columnWidth($key)));
            }
            if (str_ends_with($key, '_kg')) {
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.###');
            }
            if ($this->isMoney($key)) {
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::FORMAT_MONEY);
            }
        }

        // Excel không tự giãn dòng có chữ xuống dòng khi mở file do code tạo, nên đặt chiều cao theo số dòng chữ.
        $sheet->getRowDimension($row)->setRowHeight($lines * self::LINE_HEIGHT + 3);
    }

    /**
     * Ước lượng số dòng chữ khi xuống dòng trong ô rộng $width ký tự.
     * ponytail: đếm ký tự, không đo font thật; chữ hoa dài có thể thiếu 1 dòng — tăng LINE_HEIGHT nếu cần.
     */
    private function wrappedLines(string $text, float $width): int
    {
        $charsPerLine = max(1, (int) floor($width * 0.9));

        return (int) collect(preg_split('/\R/u', $text))
            ->sum(fn (string $line): int => max(1, (int) ceil(mb_strlen($line) / $charsPerLine)));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: ?string}>  $columns
     */
    private function writeTotalRow(Worksheet $sheet, array $columns, int $row, int $firstDataRow, int $lastDataRow): void
    {
        $sheet->setCellValue("A{$row}", 'Tổng');
        $sheet->mergeCells("A{$row}:C{$row}");

        foreach ($columns as $index => [$key]) {
            if (! $this->isMoney($key)) {
                continue;
            }
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$column}{$row}", "=SUM({$column}{$firstDataRow}:{$column}{$lastDataRow})");
            $sheet->getStyle("{$column}{$row}")->getNumberFormat()->setFormatCode(self::FORMAT_MONEY);
        }

        $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($columns)).$row)->getFont()->setBold(true);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function valueFor(string $key, array $values): mixed
    {
        if (str_starts_with($key, 'fee:')) {
            $amount = collect(explode(',', substr($key, 4)))->sum(fn (string $code): float => (float) ($values['_fees'][$code] ?? 0));

            return $amount > 0 ? $amount : null;
        }

        $value = $values[$key] ?? null;

        // Ô tiền phí = 0 để trống như file mẫu; đơn giá/tổng vẫn ghi 0.
        return $key === 'fee_rest' && (float) $value === 0.0 ? null : $value;
    }

    private function isMoney(string $key): bool
    {
        return in_array($key, self::MONEY_KEYS, true) || str_starts_with($key, 'fee:');
    }

    private function columnWidth(string $key): float
    {
        return match (true) {
            $key === 'stt' => 5,
            in_array($key, ['pickup', 'drop'], true) => 50,
            in_array($key, ['pickup_name', 'drop_name', 'delivery_note_numbers'], true) => 24,
            $key === 'note' => 38,
            in_array($key, ['drop_old_address', 'drop_zone'], true) => 24,
            in_array($key, ['date', 'unload_date', 'plate'], true) => 12,
            $this->isMoney($key) => 14,
            default => 10,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $dataRows
     */
    private function vatLabel(Collection $dataRows): string
    {
        $percents = $dataRows->pluck('_vat_percent')->filter(fn (?float $percent): bool => $percent !== null)->unique();

        return $percents->count() === 1 ? $this->percent((float) $percents->first()) : '';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function invoiceLine(array $options): ?string
    {
        if (blank($options['invoice_symbol'] ?? null) && blank($options['invoice_number'] ?? null)) {
            return null;
        }

        $date = $this->carbon($options['invoice_date'] ?? null);

        return '(Kèm theo hóa đơn GTGT Ký hiệu : '.($options['invoice_symbol'] ?? '')
            .'; Số '.($options['invoice_number'] ?? '')
            .' ; ngày '.($date?->format('d/m/Y') ?? '').')';
    }

    private function address(?Location $location, ?string $fallback): string
    {
        return (string) ($location?->address ?: $fallback ?: $location?->name);
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',').'%';
    }

    private function carbon(mixed $value): ?Carbon
    {
        return match (true) {
            $value instanceof CarbonInterface => Carbon::instance($value),
            blank($value) => null,
            default => Carbon::parse($value),
        };
    }
}
