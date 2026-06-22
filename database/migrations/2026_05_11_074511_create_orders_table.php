<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code', 50)->unique()->comment('Mã đơn hàng tự sinh');

            $table->enum('type', ['HHHK', 'external'])->default('HHHK')
                ->comment('Loại đơn: HHHK hoặc Hàng ngoài');
            $table->foreignId('area_id')
                ->constrained('areas')
                ->comment('Phân nhánh: NBA / TN / BN / NBO / Đi tỉnh...');

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->comment('Khách hàng');

            $table->string('cargo_name')->nullable()->comment('Tên hàng');
            $table->enum('cargo_type', ['GCR', 'DGR'])->default('GCR')
                ->comment('Loại hàng: GCR thường | DGR hàng nguy hiểm');
            $table->integer('total_packages')->nullable()->comment('Tổng số kiện');
            $table->decimal('total_weight', 10, 2)->nullable()->comment('Trọng lượng (kg)');

            $table->foreignId('pickup_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete()
                ->comment('Điểm đi/lấy hàng (FK đến locations)');
            $table->string('pickup_address')->nullable()->comment('Địa chỉ lấy hàng (manual nếu không có trong danh mục)');
            $table->string('pickup_contact')->nullable()->comment('Người liên hệ tại điểm lấy');
            $table->string('pickup_phone', 20)->nullable();
            $table->datetime('planned_loading_at')->nullable()->comment('Thời gian dự kiến đóng hàng');

            $table->foreignId('trip_id')
                ->nullable()
                ->comment('Chuyến xe vận chuyển');
            $table->unsignedTinyInteger('trip_sequence')->nullable()->comment('Thứ tự xử lý trong chuyến');

            $table->enum('status', [
                'draft',
                'assigned',
                'sent',
                'completed',
                'cancelled',
            ])->default('draft');

            $table->enum('priority', ['urgent', 'high', 'medium', 'low'])
                ->default('medium')
                ->comment('Mức ưu tiên của đơn hàng');

            $table->boolean('is_return_trip')->default(false)->comment('Là chuyến quay đầu');
            $table->foreignId('parent_order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete()
                ->comment('Đơn gốc (dùng cho chuyến quay đầu)');

            $table->foreignId('created_by')
                ->constrained('users')
                ->comment('Điều hành tạo đơn');
            $table->datetime('sent_at')->nullable()->comment('Thời điểm gửi lệnh');
            $table->datetime('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable()->comment('Yêu cầu đặc biệt');

            $table->timestamps();
            $table->softDeletes();

            $table->index('order_code');
            $table->index(['type', 'area_id']);
            $table->index('status');
            $table->index('priority');
            $table->index('created_by');
            $table->index('planned_loading_at');
            $table->index('trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
