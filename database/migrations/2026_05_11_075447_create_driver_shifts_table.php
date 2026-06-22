<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')
                ->constrained('users')
                ->comment('Lái xe');
            $table->enum('shift_type', [
                'full',
                'morning_half',
                'night_half',
            ])->comment('Loại ca trực');

            $table->datetime('start_time')->comment('Thời gian vào ca (GPS timestamp)');
            $table->decimal('start_km', 10, 1)->nullable()->comment('Km bắt đầu ca');
            $table->decimal('start_gps_lat', 10, 7)->nullable();
            $table->decimal('start_gps_lng', 10, 7)->nullable();

            $table->datetime('end_time')->nullable()->comment('Thời gian kết thúc ca');
            $table->decimal('end_km', 10, 1)->nullable()->comment('Km kết thúc ca');
            $table->decimal('end_gps_lat', 10, 7)->nullable();
            $table->decimal('end_gps_lng', 10, 7)->nullable();

            $table->decimal('total_km', 8, 1)->nullable()->comment('Tổng km trong ca = end_km - start_km');
            $table->decimal('total_km_loaded', 8, 1)->nullable()->comment('Tổng km có hàng');
            $table->decimal('total_km_empty', 8, 1)->nullable()->comment('Tổng km không hàng');

            $table->timestamps();

            $table->index(['driver_id', 'start_time']);
            $table->index('start_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_shifts');
    }
};
