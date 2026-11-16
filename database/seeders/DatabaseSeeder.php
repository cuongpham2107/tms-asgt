<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with complete master data.
     */
    public function run(): void
    {
        $this->call([
            AreaSeeder::class,
            BillingZoneSeeder::class,
            CustomerLocationMasterSeeder::class,
            PricingAndFeeSeeder::class,
            VehicleMasterSeeder::class,
            ShieldAndUserSeeder::class,
            VehicleDriverPairingSeeder::class,
        ]);

        $this->resetPostgresSequences();
    }

    /**
     * Đồng bộ lại sequence của PostgreSQL sau khi seed bản ghi có id tường minh.
     */
    protected function resetPostgresSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'areas',
            'billing_zones',
            'locations',
            'customers',
            'area_location',
            'customer_location',
            'fee_types',
            'waiting_fee_rates',
            'freight_rates',
            'vehicles',
            'roles',
            'permissions',
            'users',
        ] as $table) {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM \"{$table}\"), 0) + 1, false)"
            );
        }
    }
}
