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
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('gps_lat', 10, 7)->nullable()->after('current_mileage')->comment('Vĩ độ GPS hiện tại');
            $table->decimal('gps_lng', 10, 7)->nullable()->after('gps_lat')->comment('Kinh độ GPS hiện tại');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['gps_lat', 'gps_lng']);
        });
    }
};
