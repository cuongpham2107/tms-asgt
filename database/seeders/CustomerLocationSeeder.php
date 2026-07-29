<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class CustomerLocationSeeder extends Seeder
{
    private const CSV_HHHK = 'customer-location-hhhk.csv';

    private const CSV_HANGNGOAI = 'customer-location-hangngoai.csv';

    public function run(): void
    {
        $files = [
            'HHHK' => base_path(self::CSV_HHHK),
            'external' => base_path(self::CSV_HANGNGOAI),
        ];

        foreach ($files as $file) {
            if (! file_exists($file)) {
                $this->command->error("File not found: {$file}");

                return;
            }
        }

        $areaMap = DB::table('areas')
            ->get()
            ->mapWithKeys(fn ($a) => ["{$a->type}|{$a->code}" => $a->id])
            ->toArray();

        $allCustomers = [];
        $allPivotRows = [];

        DB::transaction(function () use ($files, &$areaMap, &$allCustomers, &$allPivotRows): void {
            DB::table('order_delivery_points')->delete();
            DB::table('orders')->delete();
            DB::table('customer_location')->delete();
            DB::table('customers')->delete();
            DB::table('locations')->delete();

            // Process each CSV independently — locations are upserted per CSV type
            // so that shared location codes (e.g. SEV) get distinct records
            // with the correct area_id for HHHK vs external.
            foreach ($files as $csvType => $file) {
                $result = $this->parseCsv($csvType, $file, $areaMap);

                if (! empty($result['locations'])) {
                    DB::table('locations')->upsert(
                        array_values($result['locations']),
                        ['code', 'area_id'],
                        ['name', 'address', 'loc_type', 'is_active', 'lat', 'lng']
                    );
                }

                // Merge customers (shared across types — upserted once after loop)
                foreach ($result['customers'] as $code => $data) {
                    $allCustomers[$code] = $data;
                }

                // Collect pivot rows with CSV type for later area resolution
                foreach ($result['pivotRows'] as $row) {
                    $allPivotRows[] = $row + ['csv_type' => $csvType];
                }
            }

            // Upsert all customers (deduplicated by code across both types)
            if (! empty($allCustomers)) {
                DB::table('customers')->upsert(
                    array_values($allCustomers),
                    'code',
                    ['name', 'address', 'is_active']
                );
            }

            // Build location map keyed by "code|area_id"
            $locationMap = DB::table('locations')
                ->get(['id', 'code', 'area_id'])
                ->mapWithKeys(fn ($l) => ["{$l->code}|{$l->area_id}" => $l->id])
                ->toArray();

            $customerMap = DB::table('customers')->pluck('id', 'code')->toArray();

            // Create customer-location pivot rows
            $insertPivot = [];
            $seen = [];

            foreach ($allPivotRows as $row) {
                $customerCode = $row['customer_code'];
                $locationCode = $row['location_code'];
                $areaCode = $row['area_code'];
                $csvType = $row['csv_type'];

                $mappedAreaCode = ($areaCode === 'Tỉnh lẻ' || $areaCode === 'Tỉnh lẻ ')
                    ? 'PROVINCE'
                    : $areaCode;

                $areaId = $areaMap["{$csvType}|{$mappedAreaCode}"] ?? null;

                $key = "{$customerCode}|{$locationCode}|{$areaId}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $customerId = $customerMap[$customerCode] ?? null;
                $locationId = $areaId !== null
                    ? ($locationMap["{$locationCode}|{$areaId}"] ?? null)
                    : null;

                if ($customerId !== null && $locationId !== null) {
                    $insertPivot[] = [
                        'customer_id' => $customerId,
                        'location_id' => $locationId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if (! empty($insertPivot)) {
                DB::table('customer_location')->insert($insertPivot);
            }
        });

        $this->command->info(sprintf(
            'Imported %d customers, locations, and customer-location links.',
            count($allCustomers)
        ));

        if ($this->command) {
            $this->command->info('Running locations:geocode command...');
            Artisan::call('locations:geocode', [], $this->command->getOutput());
        } else {
            Artisan::call('locations:geocode');
        }
    }

    /**
     * Parse a single CSV file and return collected data.
     *
     * @return array{customers: array, locations: array, pivotRows: array}
     */
    private function parseCsv(string $csvType, string $file, array &$areaMap): array
    {
        $customers = [];
        $locations = [];
        $pivotRows = [];

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);

        $colIdx = [
            'customerCode' => array_search('Mã khách hàng', $header),
            'locationCode' => array_search('Địa điểm viết tắt', $header),
            'companyName' => array_search('Tên công ty chi tiết', $header) !== false
                ? array_search('Tên công ty chi tiết', $header)
                : array_search('Công ty', $header),
            'address' => array_search('Địa chỉ', $header),
            'areaCode' => array_search('Khu vực', $header),
        ];

        while (($row = fgetcsv($handle)) !== false) {
            $customerCode = trim($row[$colIdx['customerCode']] ?? '');
            $locationCode = trim($row[$colIdx['locationCode']] ?? '');
            $companyName = trim($row[$colIdx['companyName']] ?? '');
            $address = trim($row[$colIdx['address']] ?? '');
            $areaCode = trim($row[$colIdx['areaCode']] ?? '');

            if (empty($customerCode) && empty($locationCode)) {
                continue;
            }

            $mappedAreaCode = $areaCode;
            if ($areaCode === 'Tỉnh lẻ' || $areaCode === 'Tỉnh lẻ ') {
                $mappedAreaCode = 'PROVINCE';
            }

            $areaId = $areaMap["{$csvType}|{$mappedAreaCode}"] ?? null;
            if ($areaId === null && ! empty($areaCode)) {
                $areaId = DB::table('areas')->insertGetId([
                    'type' => $csvType,
                    'code' => $mappedAreaCode,
                    'name' => $areaCode,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $areaMap["{$csvType}|{$mappedAreaCode}"] = $areaId;
            }

            if (! empty($customerCode)) {
                $customers[$customerCode] = [
                    'code' => $customerCode,
                    'name' => $companyName ?: $locationCode,
                    'address' => $address,
                    'is_active' => true,
                ];
            }

            if (! empty($locationCode)) {
                // Key by csvType|locationCode so the same code across HHHK/external
                // yields two distinct location rows with the correct area_id.
                $locKey = "{$csvType}|{$locationCode}";
                if (! isset($locations[$locKey])) {
                    $locations[$locKey] = [
                        'code' => $locationCode,
                        'name' => $locationCode,
                        'address' => $address,
                        'loc_type' => LocationType::Pickup->value,
                        'is_active' => true,
                        'area_id' => $areaId,
                    ];
                }
            }

            if (! empty($customerCode) && ! empty($locationCode)) {
                $pivotRows[] = [
                    'customer_code' => $customerCode,
                    'location_code' => $locationCode,
                    'area_code' => $areaCode,
                ];
            }
        }

        fclose($handle);

        return compact('customers', 'locations', 'pivotRows');
    }
}
