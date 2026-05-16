<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderTypeCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = now();

        DB::table('order_types')->upsert(
            [
                [
                    'code' => 'HHHK',
                    'name' => 'Hàng hàng không',
                    'color' => 'blue',
                    'sort_order' => 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'code' => 'external',
                    'name' => 'Hàng ngoài',
                    'color' => 'green',
                    'sort_order' => 2,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['code'],
            ['name', 'color', 'sort_order', 'is_active', 'updated_at']
        );

        $orderTypeIds = DB::table('order_types')
            ->whereIn('code', ['HHHK', 'external'])
            ->pluck('id', 'code');

        DB::table('order_categories')->upsert(
            [
                [
                    'order_type_id' => $orderTypeIds['HHHK'],
                    'code' => 'NBA',
                    'name' => 'Nội bộ A',
                    'color' => null,
                    'sort_order' => 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'order_type_id' => $orderTypeIds['HHHK'],
                    'code' => 'TN',
                    'name' => 'Tây Nam',
                    'color' => null,
                    'sort_order' => 2,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'order_type_id' => $orderTypeIds['HHHK'],
                    'code' => 'BN',
                    'name' => 'Bắc Nam',
                    'color' => null,
                    'sort_order' => 3,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'order_type_id' => $orderTypeIds['HHHK'],
                    'code' => 'NBO',
                    'name' => 'Nội bộ',
                    'color' => null,
                    'sort_order' => 4,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'order_type_id' => $orderTypeIds['external'],
                    'code' => 'PROVINCE',
                    'name' => 'Đi tỉnh',
                    'color' => null,
                    'sort_order' => 5,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
            ['order_type_id', 'code'],
            ['name', 'color', 'sort_order', 'is_active', 'updated_at']
        );
    }
}
