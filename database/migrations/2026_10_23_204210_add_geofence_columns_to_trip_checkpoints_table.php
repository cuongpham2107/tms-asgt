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
        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->decimal('eup_lat', 10, 7)->nullable()->after('gps_lng')->comment('Vị trí xe theo EUP lúc gửi mốc');
            $table->decimal('eup_lng', 10, 7)->nullable()->after('eup_lat');
            $table->dateTime('eup_recorded_at')->nullable()->after('eup_lng')->comment('Thời điểm EUP ghi nhận vị trí');
            $table->decimal('eup_speed', 6, 1)->nullable()->after('eup_recorded_at');
            $table->unsignedInteger('vehicle_distance_m')->nullable()->after('eup_speed')->comment('Khoảng cách GPS điện thoại ↔ xe (EUP)');
            $table->string('vehicle_geo_status', 20)->nullable()->after('vehicle_distance_m');
            $table->unsignedInteger('location_distance_m')->nullable()->after('vehicle_geo_status')->comment('Khoảng cách xe ↔ location của mốc');
            $table->string('location_geo_status', 20)->nullable()->after('location_distance_m');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->dropColumn([
                'eup_lat',
                'eup_lng',
                'eup_recorded_at',
                'eup_speed',
                'vehicle_distance_m',
                'vehicle_geo_status',
                'location_distance_m',
                'location_geo_status',
            ]);
        });
    }
};
