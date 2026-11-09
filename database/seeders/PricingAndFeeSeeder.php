<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PricingAndFeeSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = __DIR__.'/data/pricing_and_fees.json';
        if (! file_exists($filePath)) {
            $this->command->warn('Không tìm thấy file '.$filePath);

            return;
        }

        $data = json_decode(file_get_contents($filePath), true);

        // 1. Fee Types
        if (! empty($data['fee_types'])) {
            foreach ($data['fee_types'] as $row) {
                DB::table('fee_types')->updateOrInsert(['id' => $row['id']], $row);
            }
            $this->command->info('Đã seed '.count($data['fee_types']).' loại phí (fee_types).');
        }

        // 2. Waiting Fee Rates
        if (! empty($data['waiting_fee_rates'])) {
            foreach ($data['waiting_fee_rates'] as $row) {
                DB::table('waiting_fee_rates')->updateOrInsert(['id' => $row['id']], $row);
            }
            $this->command->info('Đã seed '.count($data['waiting_fee_rates']).' biểu phí chờ (waiting_fee_rates).');
        }

        // 3. Freight Rates
        if (! empty($data['freight_rates'])) {
            foreach (array_chunk($data['freight_rates'], 100) as $chunk) {
                foreach ($chunk as $row) {
                    DB::table('freight_rates')->updateOrInsert(['id' => $row['id']], $row);
                }
            }
            $this->command->info('Đã seed '.count($data['freight_rates']).' biểu giá cước (freight_rates).');
        }
    }
}
