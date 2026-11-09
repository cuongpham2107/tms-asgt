<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VehicleDriverPairingSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = __DIR__.'/data/vehicles.json';
        if (! file_exists($filePath)) {
            return;
        }

        $vehicles = json_decode(file_get_contents($filePath), true);
        $pairedCount = 0;

        foreach ($vehicles as $row) {
            $hasDriver = ! empty($row['current_driver_id']) || ! empty($row['even_driver_id']) || ! empty($row['odd_driver_id']);
            if ($hasDriver) {
                DB::table('vehicles')->where('id', $row['id'])->update([
                    'current_driver_id' => $row['current_driver_id'] ?? null,
                    'even_driver_id' => $row['even_driver_id'] ?? null,
                    'odd_driver_id' => $row['odd_driver_id'] ?? null,
                ]);
                $pairedCount++;
            }
        }

        $this->command->info('Đã ghép tài xế cho '.$pairedCount.' phương tiện (vehicles).');
    }
}
