<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('gps_speed', 8, 2)->nullable()->after('gps_lng')->comment('Tốc độ GPS hiện tại (km/h)');
            $table->smallInteger('gps_direction')->nullable()->after('gps_speed')->comment('Hướng di chuyển (độ)');
            $table->string('gps_address', 500)->nullable()->after('gps_direction')->comment('Địa chỉ GPS hiện tại');
            $table->dateTime('last_gps_update')->nullable()->after('gps_address')->comment('Thời điểm cập nhật GPS cuối');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['gps_speed', 'gps_direction', 'gps_address', 'last_gps_update']);
        });
    }
};
