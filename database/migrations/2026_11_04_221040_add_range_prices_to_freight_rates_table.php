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
        Schema::table('freight_rates', function (Blueprint $table) {
            $table->string('pricing_method', 32)->default('by_vehicle')->after('vehicle_type')->comment('Phương thức tính giá: by_vehicle | by_weight_range');
            $table->json('range_prices')->nullable()->after('prices')->comment('Khoảng giá theo trọng tải hàng');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('freight_rates', function (Blueprint $table) {
            $table->dropColumn(['pricing_method', 'range_prices']);
        });
    }
};
