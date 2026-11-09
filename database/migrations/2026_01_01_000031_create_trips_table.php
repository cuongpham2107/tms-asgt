<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('trip_code', 50)->unique();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('driver_shifts')->nullOnDelete();
            $table->enum('status', [
                'pending', 'started', 'arrived_pickup', 'delivering', 'arrived_delivery',
                'delivered', 'completed', 'driver_swap', 'cancelled', 'return_trip',
            ])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
            $table->decimal('total_km', 10, 1)->nullable();
            $table->decimal('total_km_loaded', 10, 1)->nullable();
            $table->decimal('total_km_empty', 10, 1)->nullable();
            $table->integer('start_location_id')->nullable();
            $table->integer('end_location_id')->nullable();
            $table->boolean('is_empty_run')->default(false);
            $table->text('note')->nullable();
            $table->boolean('is_paused')->default(false);
            $table->string('paused_reason')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
            $table->index('completed_at');
            $table->index('updated_at');
            $table->index('shift_id');
            $table->index(['vehicle_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['driver_id', 'started_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
