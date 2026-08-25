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

        DB::table('areas')->upsert(
            [
                [
                    'code' => 'NBA',
                    'name' => 'Hàng đến NBA',
                    'color' => null,
                    'sort_order' => 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'BN',
                    'name' => 'Hàng đến BN',
                    'color' => null,
                    'sort_order' => 2,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'TN',
                    'name' => 'Hàng đến TN',
                    'color' => null,
                    'sort_order' => 3,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'NBO',
                    'name' => 'Nội bộ TN',
                    'color' => null,
                    'sort_order' => 4,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'PROVINCE',
                    'name' => 'Hàng đi điểm khác 3 điểm chính',
                    'color' => null,
                    'sort_order' => 5,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['code'],
            ['name', 'color', 'sort_order', 'is_active', 'updated_at']
        );
    }
}
