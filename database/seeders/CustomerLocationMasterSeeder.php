<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerLocationMasterSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = __DIR__.'/data/customers_and_locations.json';
        if (! file_exists($filePath)) {
            $this->command->warn('Không tìm thấy file '.$filePath);

            return;
        }

        $data = json_decode(file_get_contents($filePath), true);

        // 1. Locations (phải seed trước vì customers.location_id trỏ sang locations.id)
        if (! empty($data['locations'])) {
            foreach (array_chunk($data['locations'], 100) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('locations')->updateOrInsert(['id' => $row['id']], $row);
                }
            }
            $this->command->info('Đã seed '.count($data['locations']).' địa điểm (locations).');
        }

        // 2. Customers
        if (! empty($data['customers'])) {
            foreach ($data['customers'] as $row) {
                DB::table('customers')->updateOrInsert(['id' => $row['id']], $row);
            }
            $this->command->info('Đã seed '.count($data['customers']).' khách hàng (customers).');
        }

        // 3. Customer Location Pivot
        if (! empty($data['customer_location'])) {
            foreach ($data['customer_location'] as $row) {
                DB::table('customer_location')->updateOrInsert(
                    ['customer_id' => $row['customer_id'], 'location_id' => $row['location_id']],
                    $row
                );
            }
        }

        // 4. Area Location Pivot
        if (! empty($data['area_location'])) {
            foreach (array_chunk($data['area_location'], 100) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('area_location')->updateOrInsert(
                        ['area_id' => $row['area_id'], 'location_id' => $row['location_id']],
                        $row
                    );
                }
            }
            $this->command->info('Đã seed '.count($data['area_location']).' phân nhánh địa điểm (area_location).');
        }
    }
}
