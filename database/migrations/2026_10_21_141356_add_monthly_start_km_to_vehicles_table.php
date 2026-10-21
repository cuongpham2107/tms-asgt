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
            $table->decimal('monthly_start_km', 10, 2)
                ->nullable()
                ->after('current_mileage')
                ->comment('Số km bắt đầu chu kỳ tháng (chốt lúc 08h ngày 23 hàng tháng)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('monthly_start_km');
        });
    }
};
