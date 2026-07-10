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

        $customers = [];
        $locations = [];
        $pivotRows = [];

        foreach ($files as $csvType => $file) {
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
                    if (isset($locations[$locationCode])) {
                        continue;
                    }
                    $locations[$locationCode] = [
                        'code' => $locationCode,
                        'name' => $locationCode,
                        'address' => $address,
                        'loc_type' => LocationType::Pickup->value,
                        'is_active' => true,
                        'area_id' => $areaId,
                    ];
                }

                if (! empty($customerCode) && ! empty($locationCode)) {
                    $pivotRows[] = [
                        'customer_code' => $customerCode,
                        'location_code' => $locationCode,
                    ];
                }
            }

            fclose($handle);
        }

        DB::transaction(function () use ($customers, $locations, $pivotRows) {
            DB::table('order_delivery_points')->delete();
            DB::table('orders')->delete();
            DB::table('customer_location')->delete();
            DB::table('customers')->delete();
            DB::table('locations')->delete();

            DB::table('customers')->upsert(
                array_values($customers),
                'code',
                ['name', 'address', 'is_active']
            );

            DB::table('locations')->upsert(
                array_values($locations),
                ['loc_type', 'code'],
                ['name', 'address', 'loc_type', 'is_active', 'area_id', 'lat', 'lng']
            );

            $customerMap = DB::table('customers')->pluck('id', 'code');
            $locationMap = DB::table('locations')->pluck('id', 'code');

            $insertPivot = [];
            $seen = [];
            foreach ($pivotRows as $row) {
                $key = $row['customer_code'].'|'.$row['location_code'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $customerId = $customerMap[$row['customer_code']] ?? null;
                $locationId = $locationMap[$row['location_code']] ?? null;

                if ($customerId !== null && $locationId !== null) {
                    $insertPivot[] = [
                        'customer_id' => $customerId,
                        'location_id' => $locationId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            DB::table('customer_location')->insert($insertPivot);
        });

        $this->command->info(sprintf(
            'Imported %d customers, %d locations, and %d customer-location links.',
            count($customers),
            count($locations),
            count($pivotRows)
        ));

        if ($this->command) {
            $this->command->info('Running locations:geocode command...');
            Artisan::call('locations:geocode', [], $this->command->getOutput());
        } else {
            Artisan::call('locations:geocode');
        }
    }
}
