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
            $table->foreignId('driver_id')->constrained('users');
            $table->enum('shift_type', ['full', 'morning_half', 'night_half']);
            $table->timestamp('start_time');
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('start_gps_lat', 10, 7)->nullable();
            $table->decimal('start_gps_lng', 10, 7)->nullable();
            $table->timestamp('end_time')->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
            $table->decimal('end_gps_lat', 10, 7)->nullable();
            $table->decimal('end_gps_lng', 10, 7)->nullable();
            $table->decimal('total_km', 8, 1)->nullable();
            $table->decimal('total_km_loaded', 8, 1)->nullable();
            $table->decimal('total_km_empty', 8, 1)->nullable();
            $table->boolean('is_overtime')->default(false);
            $table->timestamps();

            $table->index(['driver_id', 'start_time']);
            $table->index(['driver_id', 'end_time', 'start_time']);
            $table->index('is_overtime');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_shifts');
    }
};
