<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
        });

        DB::statement('DROP INDEX IF EXISTS driver_shifts_vehicle_id_start_time_index');

        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->dropColumn([
                'vehicle_id',
                'start_km',
                'end_km',
                'start_gps_lat',
                'start_gps_lng',
                'end_gps_lat',
                'end_gps_lng',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->constrained('vehicles')->nullable();
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
            $table->decimal('start_gps_lat', 10, 7)->nullable();
            $table->decimal('start_gps_lng', 10, 7)->nullable();
            $table->decimal('end_gps_lat', 10, 7)->nullable();
            $table->decimal('end_gps_lng', 10, 7)->nullable();
        });
    }
};
