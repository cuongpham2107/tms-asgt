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
        Schema::create('trip_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->foreignId('driver_id')
                ->constrained('users')
                ->comment('Lái xe thực hiện mốc này');
            $table->foreignId('shift_id')
                ->constrained('driver_shifts')
                ->comment('Ca trực tương ứng');
            $table->foreignId('delivery_point_id')
                ->nullable()
                ->constrained('order_delivery_points')
                ->nullOnDelete()
                ->comment('Điểm giao hàng cụ thể (nếu có nhiều điểm)');

            $table->enum('checkpoint_type', [
                'started',           // Bắt đầu chuyến
                'arrived_pickup',    // Đến điểm nhận hàng + nhập Km đến
                'left_pickup',       // Đóng hàng xong, bắt đầu đi giao
                'arrived_delivery',  // Đến điểm giao hàng
                'completed',         // Giao hàng xong + nhập Km kết thúc
                'driver_swap',       // Đảo lái
            ])->comment('Loại mốc');

            $table->datetime('occurred_at')->comment('Thời điểm thực tế (từ app)');
            $table->decimal('km_reading', 10, 1)->nullable()->comment('Số km đồng hồ xe');
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->text('voice_note')->nullable()->comment('Ghi chú (chuyển từ voice sang text)');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'checkpoint_type']);
            $table->index(['driver_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_checkpoints');
    }
};
