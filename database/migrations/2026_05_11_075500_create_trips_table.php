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
            $table->string('trip_code', 50)->unique()->comment('Mã chuyến tự sinh');
            $table->foreignId('vehicle_id')->constrained()->comment('Xe vận chuyển');
            $table->foreignId('driver_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Tài xế hiện tại');
            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('driver_shifts')
                ->nullOnDelete()
                ->comment('Ca trực');

            $table->enum('status', [
                'pending',
                'started',
                'arrived_pickup',
                'delivering',
                'arrived_delivery',
                'delivered',
                'completed',
                'driver_swap',
            ])->default('pending')->comment('Trạng thái chuyến');

            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
            $table->decimal('total_km', 10, 1)->nullable()->comment('Tổng km = end_km - start_km');
            $table->decimal('total_km_loaded', 10, 1)->nullable()->comment('Km có hàng (tính từ checkpoints)');
            $table->decimal('total_km_empty', 10, 1)->nullable()->comment('Km không hàng');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
