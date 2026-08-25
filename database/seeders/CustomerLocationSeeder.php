<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use Exception;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class CustomerLocationSeeder extends Seeder
{
    private const EXCEL_FILE = 'Form Bản tổng hợp dữ liệu.xlsx';

    public function run(): void
    {
        $excelPath = base_path(self::EXCEL_FILE);
        if (! file_exists($excelPath)) {
            $this->command?->warn("File Excel not found at {$excelPath}. Skipping Excel import.");

            return;
        }

        $reader = new class($excelPath)
        {
            private ZipArchive $zip;

            private array $sharedStrings = [];

            private array $sheetMap = [];

            public function __construct(string $filePath)
            {
                $this->zip = new ZipArchive;
                if ($this->zip->open($filePath) !== true) {
                    throw new Exception("Cannot open {$filePath}");
                }
                $this->loadSharedStrings();
                $this->loadWorkbook();
            }

            private function loadSharedStrings(): void
            {
                $content = $this->zip->getFromName('xl/sharedStrings.xml');
                if (! $content) {
                    return;
                }

                $xml = simplexml_load_string($content);
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $this->sharedStrings[] = (string) $si->t;
                    } elseif (isset($si->r)) {
                        $text = '';
                        foreach ($si->r as $r) {
                            $text .= (string) $r->t;
                        }
                        $this->sharedStrings[] = $text;
                    } else {
                        $this->sharedStrings[] = '';
                    }
                }
            }

            private function loadWorkbook(): void
            {
                $content = $this->zip->getFromName('xl/workbook.xml');
                $relsContent = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
                if (! $content || ! $relsContent) {
                    return;
                }

                $xml = simplexml_load_string($content);
                $relsXml = simplexml_load_string($relsContent);

                $relMap = [];
                foreach ($relsXml->Relationship as $rel) {
                    $relMap[(string) $rel['Id']] = (string) $rel['Target'];
                }

                foreach ($xml->sheets->sheet as $sheet) {
                    $name = (string) $sheet['name'];
                    $rId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                    $target = $relMap[$rId] ?? '';
                    $sheetPath = 'xl/'.ltrim($target, '/');
                    $this->sheetMap[$name] = $sheetPath;
                }
            }

            public function getSheetRows(string $sheetName): array
            {
                $path = $this->sheetMap[$sheetName] ?? null;
                if (! $path) {
                    return [];
                }

                $content = $this->zip->getFromName($path);
                if (! $content) {
                    return [];
                }

                $xml = simplexml_load_string($content);
                $rows = [];

                foreach ($xml->sheetData->row as $row) {
                    $rowData = [];
                    foreach ($row->c as $cell) {
                        $r = (string) $cell['r'];
                        preg_match('/([A-Z]+)(\d+)/', $r, $matches);
                        $col = $matches[1] ?? '';
                        $colIdx = $this->colLetterToIndex($col);

                        $val = '';
                        $type = (string) $cell['t'];

                        if ($type === 's') {
                            $sIndex = (int) $cell->v;
                            $val = $this->sharedStrings[$sIndex] ?? '';
                        } elseif (isset($cell->v)) {
                            $val = (string) $cell->v;
                        }

                        $rowData[$colIdx] = trim($val);
                    }

                    if (! empty($rowData)) {
                        $maxCol = max(array_keys($rowData));
                        $normalized = [];
                        for ($c = 0; $c <= $maxCol; $c++) {
                            $normalized[$c] = $rowData[$c] ?? '';
                        }
                        $rows[] = $normalized;
                    }
                }

                return $rows;
            }

            private function colLetterToIndex(string $col): int
            {
                $index = 0;
                $len = strlen($col);
                for ($i = 0; $i < $len; $i++) {
                    $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
                }

                return $index - 1;
            }
        };

        $areaMap = Area::pluck('id', 'code')->toArray();

        // 1. Locations
        $allLocations = [];

        $locHhhk = $reader->getSheetRows('DATA location HHHK');
        for ($i = 1; $i < count($locHhhk); $i++) {
            $row = $locHhhk[$i];
            $areaCode = trim($row[1] ?? '');
            $code = trim($row[2] ?? '');
            $name = trim($row[3] ?? '');
            $address = trim($row[4] ?? '');

            if (empty($code)) {
                continue;
            }

            if ($areaCode === 'Tỉnh lẻ' || $areaCode === 'Tỉnh lẻ ') {
                $areaCode = 'PROVINCE';
            }

            $areaId = $areaMap[$areaCode] ?? null;

            $allLocations[$code] = [
                'code' => $code,
                'name' => $name ?: $code,
                'address' => $address,
                'area_id' => $areaId,
                'loc_type' => LocationType::Pickup->value,
                'is_active' => true,
            ];
        }

        $locHn = $reader->getSheetRows('Data locationHàng ngoài');
        for ($i = 1; $i < count($locHn); $i++) {
            $row = $locHn[$i];
            $areaCode = trim($row[1] ?? '');
            $factoryCode = trim($row[2] ?? '');
            $locCode = trim($row[3] ?? '');
            $company = trim($row[4] ?? '');
            $address = trim($row[5] ?? '');
            $ward = trim($row[6] ?? '');
            $province = trim($row[7] ?? '');

            $code = $locCode ?: $factoryCode;
            if (empty($code)) {
                continue;
            }

            if ($areaCode === 'Tỉnh lẻ' || $areaCode === 'Tỉnh lẻ ') {
                $areaCode = 'PROVINCE';
            }

            $areaId = $areaMap[$areaCode] ?? null;

            $fullAddress = $address;
            if (! empty($ward) && ! str_contains($fullAddress, $ward)) {
                $fullAddress .= ', '.$ward;
            }
            if (! empty($province) && ! str_contains($fullAddress, $province)) {
                $fullAddress .= ', '.$province;
            }

            if (! isset($allLocations[$code])) {
                $allLocations[$code] = [
                    'code' => $code,
                    'name' => $company ?: $code,
                    'address' => $fullAddress,
                    'area_id' => $areaId,
                    'loc_type' => LocationType::Pickup->value,
                    'is_active' => true,
                ];
            }
        }

        // 2. Customers
        $custRows = $reader->getSheetRows('Khách hàng');
        $newCustomers = [];
        for ($i = 1; $i < count($custRows); $i++) {
            $row = $custRows[$i];
            $code = trim($row[1] ?? '');
            $name = trim($row[2] ?? '');
            if (empty($code)) {
                continue;
            }

            $newCustomers[] = [
                'code' => $code,
                'name' => $name,
                'address' => null,
                'is_active' => true,
            ];
        }

        DB::transaction(function () use ($allLocations, $newCustomers) {
            foreach ($allLocations as $loc) {
                Location::updateOrCreate(
                    ['code' => $loc['code']],
                    [
                        'name' => $loc['name'],
                        'address' => $loc['address'],
                        'area_id' => $loc['area_id'],
                        'loc_type' => $loc['loc_type'],
                        'is_active' => $loc['is_active'],
                    ]
                );
            }

            DB::table('customer_location')->delete();

            DB::statement('PRAGMA foreign_keys = OFF;');
            DB::table('customers')->delete();

            foreach ($newCustomers as $cust) {
                Customer::create($cust);
            }
            DB::statement('PRAGMA foreign_keys = ON;');

            $locationIds = Location::where('is_active', true)->pluck('id')->toArray();
            $customers = Customer::all();
            $locationsByCode = Location::pluck('id', 'code')->toArray();

            $pivotRows = [];
            foreach ($customers as $customer) {
                $cleanCode = preg_replace('/\s*\(.*?\)/', '', $customer->code);
                $matchedLocId = $locationsByCode[$customer->code] ?? $locationsByCode[$cleanCode] ?? null;

                if ($matchedLocId) {
                    $customer->update(['location_id' => $matchedLocId]);
                    $pivotRows[] = [
                        'customer_id' => $customer->id,
                        'location_id' => $matchedLocId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (! empty($pivotRows)) {
                DB::table('customer_location')->insert($pivotRows);
            }

            $defaultCustomer = Customer::first();
            if ($defaultCustomer) {
                DB::table('orders')->update(['customer_id' => $defaultCustomer->id]);
            }
        });

        if ($this->command) {
            $this->command->info(sprintf(
                'Imported %d locations and %d customers.',
                count($allLocations),
                count($newCustomers)
            ));
            Artisan::call('locations:geocode', [], $this->command->getOutput());
        }
    }
}
