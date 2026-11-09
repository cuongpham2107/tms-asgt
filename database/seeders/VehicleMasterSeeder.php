<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VehicleMasterSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = __DIR__.'/data/vehicles.json';
        if (! file_exists($filePath)) {
            $this->command->warn('Không tìm thấy file '.$filePath);

            return;
        }

        $vehicles = json_decode(file_get_contents($filePath), true);

        // Chèn xe với driver_id tạm thời null để tránh circular foreign key với bảng users
        foreach (array_chunk($vehicles, 100) as $chunk) {
            foreach ($chunk as $row) {
                $vehicleData = $row;
                $vehicleData['current_driver_id'] = null;
                $vehicleData['even_driver_id'] = null;
                $vehicleData['odd_driver_id'] = null;

                DB::table('vehicles')->updateOrInsert(['id' => $row['id']], $vehicleData);
            }
        }

        $this->command->info('Đã seed '.count($vehicles).' phương tiện (vehicles).');
    }
}
