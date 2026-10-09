<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DriverImportSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Đang import danh sách tài xế từ file Excel...');
        Artisan::call('drivers:import', [], $this->command?->getOutput());
    }
}
