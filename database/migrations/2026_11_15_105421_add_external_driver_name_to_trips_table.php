<?php

use App\Enums\VehicleOwnerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            if (! Schema::hasColumn('trips', 'external_driver_name')) {
                $table->string('external_driver_name')->nullable()->after('driver_id');
            }
        });

        DB::table('vehicles')
            ->where('type', VehicleOwnerType::Rent->value)
            ->whereNotNull('external_driver_name')
            ->where('external_driver_name', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($vehicles): void {
                foreach ($vehicles as $vehicle) {
                    DB::table('trips')
                        ->where('vehicle_id', $vehicle->id)
                        ->whereNull('driver_id')
                        ->whereNull('external_driver_name')
                        ->update(['external_driver_name' => trim((string) $vehicle->external_driver_name)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            if (Schema::hasColumn('trips', 'external_driver_name')) {
                $table->dropColumn('external_driver_name');
            }
        });
    }
};
