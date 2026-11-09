<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->nullable()->constrained('trips')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('delivery_point_id')->nullable()->constrained('order_delivery_points')->nullOnDelete();
            $table->enum('checkpoint_type', [
                'started', 'arrived_pickup', 'left_pickup', 'arrived_delivery',
                'completed', 'driver_swap', 'end', 'cancelled',
            ]);
            $table->timestamp('occurred_at');
            $table->decimal('km_reading', 10, 1)->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->text('voice_note')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('driver_shifts')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->decimal('eup_lat', 10, 7)->nullable();
            $table->decimal('eup_lng', 10, 7)->nullable();
            $table->timestamp('eup_recorded_at')->nullable();
            $table->decimal('eup_speed', 6, 1)->nullable();
            $table->integer('vehicle_distance_m')->nullable();
            $table->string('vehicle_geo_status', 20)->nullable();
            $table->integer('location_distance_m')->nullable();
            $table->string('location_geo_status', 20)->nullable();
            $table->integer('gps_accuracy')->nullable();
            $table->timestamp('adjusted_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['trip_id', 'occurred_at']);
            $table->index('checkpoint_type');
            $table->index('driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_checkpoints');
    }
};
