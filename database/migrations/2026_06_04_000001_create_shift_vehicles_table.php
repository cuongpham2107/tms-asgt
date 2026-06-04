<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('driver_shifts')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->datetime('start_time');
            $table->datetime('end_time')->nullable();
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
            $table->decimal('start_gps_lat', 10, 7)->nullable();
            $table->decimal('start_gps_lng', 10, 7)->nullable();
            $table->decimal('end_gps_lat', 10, 7)->nullable();
            $table->decimal('end_gps_lng', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'vehicle_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_vehicles');
    }
};
