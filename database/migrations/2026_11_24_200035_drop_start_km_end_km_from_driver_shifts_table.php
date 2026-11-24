<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Km đầu/cuối ca không còn ý nghĩa vì một ca có thể chạy nhiều xe (mỗi xe một đồng hồ riêng).
 * Nguồn sự thật là trip_checkpoints (vehicle_id + km_reading) và trips (start_km/end_km).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->dropColumn(['start_km', 'end_km']);
        });
    }

    public function down(): void
    {
        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
        });
    }
};
