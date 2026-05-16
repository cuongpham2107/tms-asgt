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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_code', 50)->unique()->comment('Mã đơn hàng tự sinh');

            // Phân loại đơn — FK thay cho enum
            $table->foreignId('order_type_id')
                ->constrained('order_types')
                ->comment('Loại đơn: HHHK hoặc Hàng ngoài');
            $table->foreignId('order_category_id')
                ->constrained('order_categories')
                ->comment('Phân nhánh: NBA / TN / BN / NBO / Đi tỉnh...');

            // Khách hàng
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->comment('Khách hàng');

            // Hàng hóa (chủ yếu dùng cho hàng ngoài)
            $table->string('cargo_name')->nullable()->comment('Tên hàng');
            $table->enum('cargo_type', ['GCR', 'DGR'])->default('GCR')
                ->comment('Loại hàng: GCR thường | DGR hàng nguy hiểm');
            $table->integer('total_packages')->nullable()->comment('Tổng số kiện');
            $table->decimal('total_weight', 10, 2)->nullable()->comment('Trọng lượng (kg)');

            // Địa điểm lấy hàng
            $table->foreignId('pickup_location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete()
                ->comment('Điểm đi/lấy hàng (FK đến locations)');
            $table->string('pickup_address')->nullable()->comment('Địa chỉ lấy hàng (manual nếu không có trong danh mục)');
            $table->string('pickup_contact')->nullable()->comment('Người liên hệ tại điểm lấy');
            $table->string('pickup_phone', 20)->nullable();
            $table->datetime('planned_loading_at')->nullable()->comment('Thời gian dự kiến đóng hàng');

            // Phân công xe & lái
            $table->foreignId('vehicle_id')
                ->nullable()
                ->constrained('vehicles')
                ->nullOnDelete()
                ->comment('Biển số xe được gán');
            // $table->string('vehicle_owner')->nullable()->comment('Chủ xe (snapshot tại thời điểm gán)');
            // $table->enum('vehicle_type_snapshot', [
            //     'normal', 'cold', 'anti_vibration', 'container', 'flatbed', 'bat_wing', 'other',
            // ])->nullable()->comment('Loại xe (snapshot)');
            // $table->decimal('load_capacity', 8, 2)->nullable()->comment('Tải trọng tính cước');
            $table->foreignId('driver_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Lái xe được gán');

            // Trạng thái đơn hàng
            $table->enum('status', [
                'draft',              // Kế hoạch — chưa gán xe
                'assigned',           // Đã gán xe + lái, chưa gửi
                'sent',               // Đã gửi lệnh cho lái xe
                'started',            // Lái xe bắt đầu chuyến
                'arrived_pickup',     // Đến điểm lấy hàng
                'delivering',         // Đang giao hàng (đã rời điểm lấy)
                'arrived_delivery',   // Đến điểm giao hàng
                'delivered',          // Giao hàng xong (chưa đủ thông tin)
                'completed',          // Hoàn thành (đủ tất cả thông tin)
                'driver_swap',        // Đang đảo lái
                'cancelled',          // Hủy chuyến
                'trashed',            // Đã xóa (thùng rác)
            ])->default('draft');

            // Chuyến quay đầu
            $table->boolean('is_return_trip')->default(false)->comment('Là chuyến quay đầu');
            $table->foreignId('parent_order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete()
                ->comment('Đơn gốc (dùng cho chuyến quay đầu)');

            // Audit
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
            $table->index(['order_type_id', 'order_category_id']);
            $table->index('status');
            $table->index('created_by');
            $table->index('planned_loading_at');
            $table->index(['vehicle_id', 'status']);
            $table->index(['driver_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
