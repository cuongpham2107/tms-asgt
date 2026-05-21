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
        Schema::create('order_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Tên mẫu đơn định kỳ');
            $table->json('order_data')->comment('Dữ liệu mẫu đơn hàng (JSON đủ các trường của orders)');
            $table->unsignedInteger('quantity')->default(1)->comment('Số lượng đơn tạo mỗi lần');
            $table->string('cron_expression', 100)->nullable()
                ->comment('Biểu thức cron cho tự động tạo, ví dụ: 0 6 * * * (6h sáng hàng ngày)');
            $table->time('daily_run_at')->nullable()->comment('Giờ chạy hàng ngày (nếu không dùng cron)');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')
                ->constrained('users');
            $table->timestamps();

            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_templates');
    }
};
