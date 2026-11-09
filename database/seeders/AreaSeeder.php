<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        $areas = [
            [
                'id' => 1,
                'type' => 'HHHK',
                'code' => 'NBA',
                'name' => 'Hàng đến NBA',
                'color' => null,
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'type' => 'HHHK',
                'code' => 'TN',
                'name' => 'Hàng đến TN',
                'color' => null,
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'type' => 'HHHK',
                'code' => 'BN',
                'name' => 'Hàng đến BN',
                'color' => null,
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 4,
                'type' => 'HHHK',
                'code' => 'NBO',
                'name' => 'Nội bộ TN',
                'color' => null,
                'sort_order' => 4,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 5,
                'type' => 'external',
                'code' => 'PROVINCE',
                'name' => 'Hàng đi điểm khác 3 điểm chính',
                'color' => null,
                'sort_order' => 5,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 6,
                'type' => null,
                'code' => 'ASG',
                'name' => 'Hàng GOM',
                'color' => null,
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($areas as $area) {
            DB::table('areas')->updateOrInsert(
                ['id' => $area['id']],
                $area
            );
        }
    }
}
