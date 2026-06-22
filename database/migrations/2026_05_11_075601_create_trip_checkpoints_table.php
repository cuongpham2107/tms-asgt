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
            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete()
                ->comment('Chuyến xe');
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete()
                ->comment('Đơn hàng liên quan (nếu có)');
            $table->foreignId('delivery_point_id')
                ->nullable()
                ->constrained('order_delivery_points')
                ->nullOnDelete()
                ->comment('Điểm giao hàng cụ thể (nếu có nhiều điểm)');

            $table->enum('checkpoint_type', [
                'started',
                'arrived_pickup',
                'left_pickup',
                'arrived_delivery',
                'completed',
                'driver_swap',
            ])->comment('Loại mốc');

            $table->datetime('occurred_at')->comment('Thời điểm thực tế (từ app)');
            $table->decimal('km_reading', 10, 1)->nullable()->comment('Số km đồng hồ xe');
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->text('voice_note')->nullable()->comment('Ghi chú (chuyển từ voice sang text)');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['trip_id', 'checkpoint_type']);
            $table->index(['order_id', 'checkpoint_type']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_checkpoints');
    }
};
