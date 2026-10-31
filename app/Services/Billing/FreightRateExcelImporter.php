<?php

namespace App\Services\Billing;

use App\Enums\CargoType;
use App\Enums\VehicleType;
use App\Models\BillingZone;
use App\Models\Customer;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\WaitingFeeRate;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use XMLReader;
use ZipArchive;

/**
 * Nhập bảng giá từ file "QUY TRÌNH THỰC HIỆN SỐ LIỆU.xlsx" (sheet 3.1, 3.2, 3.3, 3.4, 4 + tra cứu 5, 6.1, 6.2).
 *
 * - Tạo khu vực tính cước (billing_zones) theo tên: sheet 5 cột D và cột "Điểm đi/đến viết tắt" của các bảng giá;
 *   mã khu vực tự tạo từ tên.
 * - Gán khu vực cho địa điểm: sheet 5 mã nhà máy cột C → locations.code, khu vực cột D.
 * - Đơn giá khoá theo khách hàng + khu vực đi + khu vực đến + loại hàng + loại hình + loại xe.
 * Chạy lại nhiều lần không nhân đôi: cùng mã khu vực / cùng khoá giá thì cập nhật.
 */
class FreightRateExcelImporter
{
    private const SHEET_LOCATIONS = '5. Địa điểm';

    private const SHEET_ONE_DROP = '3.1 Đơn giá 1 điểm trả';

    private const SHEET_VFC_COLD = '3.2 Đơn giá VFC Lạnh';

    private const SHEET_VFC_NORMAL = '3.3 Đơn giá VFC Thường';

    private const SHEET_TWO_DROP = '3.4 Đơn giá 2 điểm trả ALSE';

    private const SHEET_SURCHARGE = '4. Phụ phí';

    private const SHEET_VFC_NORMAL_CODES = '6.1 VFC code tìm giá thường';

    private const SHEET_VFC_COLD_CODES = '6.2 VFC code tìm giá lạnh';

    private const VFC_COLD_RATE_TYPE = 'Hàng lạnh';

    /** Sheet 3.4: giá trọn chuyến 2 điểm trả, lưu theo tuyến điểm đi → điểm trả đầu với loại hình riêng. */
    private const TWO_DROP_RATE_TYPE = '2 điểm trả';

    /** Cột tải trọng hàng của sheet 3.3 → cận trên (tấn). */
    private const VFC_NORMAL_TIERS = ['G' => 2, 'H' => 3, 'I' => 4, 'J' => 7];

    private const CUSTOMER_ALIASES = ['vfc' => 'asgs (vfc)'];

    private Spreadsheet $workbook;

    /** @var array<string, true> tên các khu vực (chuẩn hoá) có trong file */
    private array $zoneNames = [];

    /** @var array<int, string> id địa điểm → tên khu vực theo sheet 5 */
    private array $zoneCodeByLocationId = [];

    /** @var array<string, int> mã KH (chuẩn hoá) → id */
    private array $customerIds = [];

    /** @var array<string, array<string, mixed>> */
    private array $freightRates = [];

    /** @var array<string, array<string, mixed>> */
    private array $waitingFees = [];

    /** @var array<string, string> */
    private array $warnings = [];

    /** @var array<string, list<string>> mã khu vực có giá → các dòng giá */
    private array $zoneRateSources = [];

    /**
     * @return array{freight_rates: int, waiting_fees: int, zones: int, zones_created: int, locations_assigned: int, warnings: list<string>, zones_without_locations: array<string, list<string>>}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $this->workbook = $this->load($path);
        $this->customerIds = Customer::query()->pluck('id', 'code')
            ->mapWithKeys(fn (int $id, string $code): array => [$this->normalize($code) => $id])
            ->all();
        $this->readLocationZones($path);

        $this->readOneDropRates();
        $this->readTwoDropRates();
        $this->readVfcRates(self::SHEET_VFC_NORMAL_CODES, self::SHEET_VFC_NORMAL, normal: true);
        $this->readVfcRates(self::SHEET_VFC_COLD_CODES, self::SHEET_VFC_COLD, normal: false);
        $this->readWaitingFees();

        $existingZoneIds = BillingZone::query()->get(['id', 'name'])
            ->mapWithKeys(fn (BillingZone $zone): array => [BillingZone::normalizeName($zone->name) => $zone->id])
            ->all();
        $locationsToAssign = Location::query()->get(['id', 'billing_zone_id'])
            ->filter(fn (Location $location): bool => isset($this->zoneCodeByLocationId[$location->id])
                && $location->billing_zone_id !== ($existingZoneIds[$this->zoneCodeByLocationId[$location->id]] ?? false));
        $zonesWithLocations = Location::query()->whereNotNull('billing_zone_id')->with('billingZone:id,name')->get()
            ->pluck('billingZone.name')
            ->merge($this->zoneCodeByLocationId)
            ->flip();

        if (! $dryRun) {
            DB::transaction(function () use ($locationsToAssign, $existingZoneIds): void {
                // Khu vực đã có giữ nguyên mã (có thể đã sửa tay); khu vực mới tự tạo mã từ tên.
                $zoneIds = $existingZoneIds;
                foreach (array_keys($this->zoneNames) as $name) {
                    $zoneIds[$name] ??= BillingZone::create(['name' => $name])->id;
                }
                foreach ($locationsToAssign as $location) {
                    $location->update(['billing_zone_id' => $zoneIds[$this->zoneCodeByLocationId[$location->id]]]);
                }
                foreach ($this->freightRates as $rate) {
                    $rate = [
                        ...collect($rate)->except(['origin_zone', 'destination_zone'])->all(),
                        'origin_zone_id' => $zoneIds[$rate['origin_zone']],
                        'destination_zone_id' => $zoneIds[$rate['destination_zone']],
                    ];
                    FreightRate::updateOrCreate(collect($rate)->except(['prices', 'notes'])->all(), collect($rate)->only(['prices', 'notes'])->all());
                }
                foreach ($this->waitingFees as $fee) {
                    WaitingFeeRate::updateOrCreate(
                        collect($fee)->only(['customer_id', 'surcharge_type', 'waiting_mode', 'effective_from', 'effective_to'])->all(),
                        collect($fee)->only(['tiers', 'fee_block_minutes', 'grace_minutes'])->all(),
                    );
                }
            });
        }

        return [
            'freight_rates' => count($this->freightRates),
            'waiting_fees' => count($this->waitingFees),
            'zones' => count($this->zoneNames),
            'zones_created' => count(array_diff_key($this->zoneNames, $existingZoneIds)),
            'locations_assigned' => $locationsToAssign->count(),
            'warnings' => array_values($this->warnings),
            'zones_without_locations' => array_diff_key($this->zoneRateSources, $zonesWithLocations->all()),
        ];
    }

    private function load(string $path): Spreadsheet
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([
            self::SHEET_ONE_DROP, self::SHEET_VFC_COLD, self::SHEET_VFC_NORMAL,
            self::SHEET_TWO_DROP, self::SHEET_SURCHARGE, self::SHEET_VFC_NORMAL_CODES, self::SHEET_VFC_COLD_CODES,
        ]);
        // Chỉ đọc cột A–Z, bỏ phần định dạng thừa phía sau.
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return strlen($columnAddress) === 1;
            }
        });

        return $reader->load($path);
    }

    /**
     * Sheet 5 nối địa điểm với khu vực: mã nhà máy (cột C) = locations.code, cột D "Địa điểm viết tắt" = tên khu vực.
     * Mã trong hệ thống khác Excel (VD "SÔNG CÔNG" vs "SR TECH") thì khớp theo tên công ty (cột E), rồi theo địa chỉ
     * (cột F) — chỉ khi tên / địa chỉ đó thuộc đúng một khu vực.
     */
    private function readLocationZones(string $path): void
    {
        $zonesByKey = ['code' => [], 'name' => [], 'address' => []];
        foreach ($this->locationSheetRows($path) as [$locationCode, $zoneName, $company, $address]) {
            $zone = $this->zone($zoneName);
            if ($zone === null) {
                continue;
            }
            foreach (['code' => $locationCode, 'name' => $company, 'address' => $address] as $key => $value) {
                if ($this->normalize($value) !== '') {
                    $zonesByKey[$key][$this->normalize($value)][$zone] = true;
                }
            }
        }

        foreach (Location::query()->get(['id', 'code', 'name', 'address']) as $location) {
            foreach (['code' => $location->code, 'name' => $location->name, 'address' => $location->address] as $key => $value) {
                $zones = array_keys($zonesByKey[$key][$this->normalize($value)] ?? []);
                if (count($zones) === 1) {
                    $this->zoneCodeByLocationId[$location->id] = $zones[0];
                    break;
                }
            }
        }
    }

    /**
     * Ghi nhận khu vực theo tên, trả về tên chuẩn hoá (null nếu ô trống).
     */
    private function zone(?string $name): ?string
    {
        $name = BillingZone::normalizeName($name);
        if ($name !== null) {
            $this->zoneNames[$name] = true;
        }

        return $name;
    }

    /**
     * Đọc streaming sheet "5. Địa điểm" (cột C mã nhà máy, D tên khu vực, E tên công ty, F địa chỉ).
     * Sheet này có định dạng tới cột XFA (~70MB XML) nên không nạp bằng PhpSpreadsheet được — tốn vài GB RAM.
     *
     * @return \Generator<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function locationSheetRows(string $path): \Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return;
        }

        $sheetFile = $this->sheetFileName($zip, self::SHEET_LOCATIONS);
        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            foreach (simplexml_load_string($sharedXml)->si as $item) {
                $text = (string) $item->t;
                foreach ($item->r as $run) {
                    $text .= (string) $run->t;
                }
                $sharedStrings[] = $text;
            }
        }
        $zip->close();

        if ($sheetFile === null) {
            return;
        }

        $reader = new XMLReader;
        $reader->open('zip://'.$path.'#'.$sheetFile);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $values = ['C' => '', 'D' => '', 'E' => '', 'F' => ''];
            foreach (simplexml_load_string($reader->readOuterXml())->c as $cell) {
                $column = preg_replace('/\d+/', '', (string) $cell['r']);
                if (! array_key_exists($column, $values)) {
                    continue;
                }

                $raw = isset($cell->is) ? (string) $cell->is->t : (string) $cell->v;
                $values[$column] = (string) $cell['t'] === 's' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
            }

            yield [$values['C'], $values['D'], $values['E'], $values['F']];
        }

        $reader->close();
    }

    private function sheetFileName(ZipArchive $zip, string $sheetName): ?string
    {
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $relations = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

        foreach ($workbook->sheets->sheet as $sheet) {
            if ((string) $sheet['name'] !== $sheetName) {
                continue;
            }

            $relationId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($relations->Relationship as $relation) {
                if ((string) $relation['Id'] === $relationId) {
                    return 'xl/'.ltrim(str_replace('/xl/', '', (string) $relation['Target']), '/');
                }
            }
        }

        return null;
    }

    private function readOneDropRates(): void
    {
        $sheet = $this->workbook->getSheetByName(self::SHEET_ONE_DROP);
        if ($sheet === null) {
            return;
        }

        $tiers = $this->vehicleTiers($sheet, range('J', 'S'), headerRow: 1);

        foreach ($this->rows($sheet, 2) as $row) {
            $this->addRates(
                sheet: $sheet,
                row: $row,
                customer: $this->cell($sheet, "D{$row}"),
                cargoType: $this->cell($sheet, "B{$row}"),
                rateType: $this->cell($sheet, "C{$row}"),
                zones: [$this->cell($sheet, "E{$row}"), $this->cell($sheet, "F{$row}")],
                tiers: $tiers,
            );
        }
    }

    private function readTwoDropRates(): void
    {
        $sheet = $this->workbook->getSheetByName(self::SHEET_TWO_DROP);
        if ($sheet === null) {
            return;
        }

        $tiers = $this->vehicleTiers($sheet, range('L', 'O'), headerRow: 1);

        foreach ($this->rows($sheet, 2) as $row) {
            $this->addRates(
                sheet: $sheet,
                row: $row,
                customer: $this->cell($sheet, "D{$row}"),
                cargoType: $this->cell($sheet, "B{$row}"),
                rateType: self::TWO_DROP_RATE_TYPE,
                zones: [$this->cell($sheet, "E{$row}"), $this->cell($sheet, "F{$row}")],
                tiers: $tiers,
            );
        }
    }

    /**
     * VFC: bảng giá theo mã tuyến (3.2 / 3.3), sheet 6.x cho biết cặp địa chỉ cụ thể nào dùng mã tuyến nào.
     */
    private function readVfcRates(string $codesSheetName, string $priceSheetName, bool $normal): void
    {
        $codesSheet = $this->workbook->getSheetByName($codesSheetName);
        $priceSheet = $this->workbook->getSheetByName($priceSheetName);
        if ($codesSheet === null || $priceSheet === null) {
            return;
        }

        $tiers = $normal
            ? array_map(fn (int $tonnage): array => ['tonnage' => $tonnage, 'vehicle_type' => null], self::VFC_NORMAL_TIERS)
            : $this->vehicleTiers($priceSheet, range('G', 'N'), headerRow: 1);

        $priceRowByCode = [];
        foreach ($this->rows($priceSheet, $normal ? 5 : 2) as $row) {
            $code = $this->normalize($this->cell($priceSheet, "A{$row}"));
            if ($code !== '') {
                $priceRowByCode[$code] ??= $row;
            }
        }

        [$customerCol, $codeCol, $originCol, $destinationCol, $firstRow] = $normal ? ['B', 'F', 'D', 'E', 2] : ['C', 'G', 'E', 'F', 3];

        foreach ($this->rows($codesSheet, $firstRow) as $row) {
            $code = $this->cell($codesSheet, "{$codeCol}{$row}");
            if ($code === '') {
                continue;
            }
            $priceRow = $priceRowByCode[$this->normalize($code)] ?? null;

            if ($priceRow === null) {
                $this->warn("{$codesSheetName}!{$codeCol}{$row}: mã tuyến \"{$code}\" không có trong sheet {$priceSheetName}");

                continue;
            }

            $this->addRates(
                sheet: $priceSheet,
                row: $priceRow,
                customer: $this->cell($codesSheet, "{$customerCol}{$row}"),
                cargoType: 'GCR',
                rateType: $normal ? '' : self::VFC_COLD_RATE_TYPE,
                zones: [$this->cell($codesSheet, "{$originCol}{$row}"), $this->cell($codesSheet, "{$destinationCol}{$row}")],
                tiers: $tiers,
                source: "{$codesSheetName}!{$row}",
            );
        }
    }

    /**
     * Một dòng Excel → một dòng giá cho mỗi (tuyến, loại xe), đơn giá các bậc tải gom vào cột prices.
     *
     * @param  array{0: string, 1: string}  $zones  [khu vực điểm đi, khu vực điểm đến] — cột "viết tắt" của bảng giá
     * @param  array<string, array{tonnage: float|int, vehicle_type: ?VehicleType}>  $tiers  cột → bậc tải
     */
    private function addRates(
        Worksheet $sheet,
        int $row,
        string $customer,
        string $cargoType,
        string $rateType,
        array $zones,
        array $tiers,
        ?string $source = null,
    ): void {
        $source ??= "{$sheet->getTitle()}!{$row}";
        $customerId = $this->customerId($customer, $source);

        [$origin, $destination] = [BillingZone::normalizeName($zones[0]), BillingZone::normalizeName($zones[1])];
        if ($customerId === null || $origin === null || $destination === null) {
            return;
        }

        [$origin, $destination] = [$this->zone($origin), $this->zone($destination)];
        $this->zoneRateSources[$origin][] = $source;
        $this->zoneRateSources[$destination][] = $source;

        $pricesByVehicleType = [];
        foreach ($tiers as $column => $tier) {
            $price = (float) $this->cell($sheet, "{$column}{$row}");
            if ($price > 0) {
                $pricesByVehicleType[$tier['vehicle_type']?->value ?? ''][FreightRate::tonnageKey($tier['tonnage'])] = round($price, 2);
            }
        }

        foreach ($pricesByVehicleType as $vehicleType => $prices) {
            $rate = [
                'customer_id' => $customerId,
                'origin_zone' => $origin,
                'destination_zone' => $destination,
                'cargo_type' => CargoType::tryFrom(strtoupper($cargoType)) ?? CargoType::Gcr,
                'rate_type' => trim($rateType),
                'vehicle_type' => $vehicleType === '' ? null : VehicleType::from($vehicleType),
                'effective_from' => null,
                'effective_to' => null,
            ];

            $key = implode('|', [$customerId, $origin, $destination, $rate['cargo_type']->value, $rate['rate_type'], $vehicleType]);
            $existing = $this->freightRates[$key] ?? null;

            foreach ($prices as $tonnage => $price) {
                $previous = $existing['prices'][$tonnage] ?? null;
                if ($previous !== null && (float) $previous !== $price) {
                    $this->warn("{$source}: trùng tuyến với {$existing['notes']} nhưng khác giá ở bậc {$tonnage}T — lấy giá dòng sau");
                }
            }

            $this->freightRates[$key] = [
                ...$rate,
                'prices' => array_replace($existing['prices'] ?? [], $prices),
                'notes' => "Import từ Excel ({$source})",
            ];
        }
    }

    private function readWaitingFees(): void
    {
        $sheet = $this->workbook->getSheetByName(self::SHEET_SURCHARGE);
        if ($sheet === null) {
            return;
        }

        $tiers = $this->vehicleTiers($sheet, range('F', 'N'), headerRow: 1);
        $fees = [];
        $freeMinutes = [];
        $graceMinutes = [];
        $inGraceTable = false;

        foreach ($this->rows($sheet, 2) as $row) {
            if ($this->normalize($this->cell($sheet, "A{$row}")) === 'code') {
                $inGraceTable = true;

                continue;
            }

            $customerId = $this->customerId($this->cell($sheet, "C{$row}"), "{$sheet->getTitle()}!{$row}");
            if ($customerId === null) {
                continue;
            }

            $type = trim($this->cell($sheet, "B{$row}"));

            if ($inGraceTable) {
                $graceMinutes["{$customerId}|{$type}"] = (int) $this->cell($sheet, "D{$row}");

                continue;
            }

            $content = $this->normalize($this->cell($sheet, "D{$row}"));
            $mode = match (true) {
                str_contains($content, 'không nổ máy') => WaitingFeeRate::MODE_ENGINE_OFF,
                str_contains($content, 'nổ máy') => WaitingFeeRate::MODE_ENGINE_ON,
                default => WaitingFeeRate::MODE_NORMAL,
            };
            $isFee = str_starts_with($content, 'phí giờ chờ');
            $blockMinutes = str_contains($this->normalize($this->cell($sheet, "E{$row}")), '30') ? 30 : 60;

            if (! $isFee && str_starts_with($content, 'miễn phí') && ! in_array($content, ['miễn phí', 'miễn phí nổ máy', 'miễn phí không nổ máy'], true)) {
                $this->warn("{$sheet->getTitle()}!{$row}: \"{$this->cell($sheet, "D{$row}")}\" phụ thuộc nơi chờ — lấy mức miễn phí thấp nhất");
            }

            foreach ($tiers as $column => $tier) {
                $value = $this->cell($sheet, "{$column}{$row}");
                if ($value === '') {
                    continue;
                }

                $key = "{$customerId}|{$type}|{$mode}|{$tier['tonnage']}";
                if ($isFee) {
                    $fees[$key] = ['fee' => (float) $value, 'fee_block_minutes' => $blockMinutes];
                } elseif (str_starts_with($content, 'miễn phí')) {
                    $minutes = (int) round((float) $value * 60);
                    $freeMinutes[$key] = min($freeMinutes[$key] ?? $minutes, $minutes);
                }
            }
        }

        foreach ($fees as $key => $fee) {
            [$customerId, $type, $mode, $tonnage] = explode('|', $key);
            $rowKey = "{$customerId}|{$type}|{$mode}";

            $this->waitingFees[$rowKey] ??= [
                'customer_id' => (int) $customerId,
                'surcharge_type' => $type,
                'waiting_mode' => $mode,
                'effective_from' => null,
                'effective_to' => null,
                'fee_block_minutes' => $fee['fee_block_minutes'],
                'grace_minutes' => $graceMinutes["{$customerId}|{$type}"] ?? $graceMinutes["{$customerId}|"] ?? 0,
                'tiers' => [],
            ];

            $this->waitingFees[$rowKey]['tiers'][] = [
                'tonnage' => (float) $tonnage,
                'fee' => $fee['fee'],
                'free_minutes' => $freeMinutes[$key] ?? 0,
            ];
        }
    }

    /**
     * Đọc dòng tiêu đề bậc tải: số → tải xe; "10 CÁNH DƠI" → xe cánh dơi 10 tấn.
     *
     * @param  list<string>  $columns
     * @return array<string, array{tonnage: float, vehicle_type: ?VehicleType}>
     */
    private function vehicleTiers(Worksheet $sheet, array $columns, int $headerRow): array
    {
        $tiers = [];
        foreach ($columns as $column) {
            $header = $this->cell($sheet, "{$column}{$headerRow}");
            if (! preg_match('/\d+(?:[.,]\d+)?/', $header, $match)) {
                continue;
            }

            $tiers[$column] = [
                'tonnage' => (float) str_replace(',', '.', $match[0]),
                'vehicle_type' => str_contains($this->normalize($header), 'cánh dơi') ? VehicleType::BatWing : null,
            ];
        }

        return $tiers;
    }

    private function customerId(string $code, string $source): ?int
    {
        if (trim($code) === '') {
            return null;
        }

        $normalized = $this->normalize($code);
        $customerId = $this->customerIds[self::CUSTOMER_ALIASES[$normalized] ?? $normalized] ?? null;

        if ($customerId === null) {
            $this->warn("{$source}: chưa có khách hàng \"{$code}\"");
        }

        return $customerId;
    }

    /**
     * @return \Generator<int, int>
     */
    private function rows(Worksheet $sheet, int $firstRow): \Generator
    {
        $lastRow = $sheet->getHighestDataRow();

        for ($row = $firstRow; $row <= $lastRow; $row++) {
            yield $row;
        }
    }

    private function cell(Worksheet $sheet, string $coordinate): string
    {
        if (! $sheet->cellExists($coordinate)) {
            return '';
        }

        $cell = $sheet->getCell($coordinate);
        $value = $cell->getValue();

        if (is_string($value) && str_starts_with($value, '=')) {
            $value = $cell->getOldCalculatedValue();
        }

        return trim((string) $value);
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    private function warn(string $message): void
    {
        $this->warnings[$message] = $message;
    }
}
