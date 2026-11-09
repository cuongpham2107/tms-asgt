<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

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
    }
}
