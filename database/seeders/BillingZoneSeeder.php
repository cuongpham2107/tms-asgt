<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BillingZoneSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = __DIR__.'/data/billing_zones.json';
        if (! file_exists($filePath)) {
            $this->command->warn('Không tìm thấy file '.$filePath);

            return;
        }

        $records = json_decode(file_get_contents($filePath), true);
        if (empty($records)) {
            return;
        }

        foreach (array_chunk($records, 100) as $chunk) {
            foreach ($chunk as $row) {
                $cleanRow = Arr::only($row, ['id', 'code', 'name', 'notes', 'created_at', 'updated_at']);
                DB::table('billing_zones')->updateOrInsert(
                    ['id' => $cleanRow['id']],
                    $cleanRow
                );
            }
        }

        $this->command->info('Đã seed '.count($records).' vùng tính cước (billing_zones).');
    }
}
