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
        Schema::create('driver_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')
                ->constrained('users')
                ->comment('Lái xe');
            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->comment('Xe sử dụng trong ca');
            $table->enum('shift_type', [
                'full',         // Cả ca
                'morning_half', // Nửa ca ngày
                'night_half',   // Nửa ca đêm
            ])->comment('Loại ca trực');

            // Vào ca
            $table->datetime('start_time')->comment('Thời gian vào ca (GPS timestamp)');
            $table->decimal('start_km', 10, 1)->nullable()->comment('Km bắt đầu ca (km gần nhất trước đó)');
            $table->decimal('start_gps_lat', 10, 7)->nullable();
            $table->decimal('start_gps_lng', 10, 7)->nullable();

            // Kết thúc ca
            $table->datetime('end_time')->nullable()->comment('Thời gian kết thúc ca');
            $table->decimal('end_km', 10, 1)->nullable()->comment('Km kết thúc ca');
            $table->decimal('end_gps_lat', 10, 7)->nullable();
            $table->decimal('end_gps_lng', 10, 7)->nullable();

            // Tổng kết km
            $table->decimal('total_km', 8, 1)->nullable()->comment('Tổng km trong ca = end_km - start_km');
            $table->decimal('total_km_loaded', 8, 1)->nullable()->comment('Tổng km có hàng');
            $table->decimal('total_km_empty', 8, 1)->nullable()->comment('Tổng km không hàng');

            $table->timestamps();

            $table->index(['driver_id', 'start_time']);
            $table->index(['vehicle_id', 'start_time']);
            $table->index('start_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_shifts');
    }
};
