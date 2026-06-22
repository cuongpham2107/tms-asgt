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
                // Hàng Hóa Hàng Không (HHHK)
                [
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
                    'type' => 'HHHK',
                    'code' => 'TN',
                    'name' => 'Hàng đến TN',
                    'color' => null,
                    'sort_order' => 2,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'type' => 'HHHK',
                    'code' => 'BN',
                    'name' => 'Hàng đến BN',
                    'color' => null,
                    'sort_order' => 3,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'type' => 'HHHK',
                    'code' => 'NBO',
                    'name' => 'Nội bộ TN',
                    'color' => null,
                    'sort_order' => 4,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                // Hàng ngoài
                [
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
                    'type' => 'external',
                    'code' => 'NBA',
                    'name' => 'Hàng đến NBA',
                    'color' => null,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'type' => 'external',
                    'code' => 'BN',
                    'name' => 'Hàng đến BN',
                    'color' => null,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'type' => 'external',
                    'code' => 'TN',
                    'name' => 'Hàng đến TN',
                    'color' => null,
                    'sort_order' => 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['type', 'code'],
            ['name', 'color', 'sort_order', 'is_active', 'updated_at']
        );
    }
}
