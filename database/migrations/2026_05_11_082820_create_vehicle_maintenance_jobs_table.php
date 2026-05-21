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
        Schema::create('vehicle_maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->comment('Xe cần bảo dưỡng / sửa chữa');

            // Phân loại
            $table->enum('job_type', [
                'periodic_maintenance', // Bảo dưỡng định kỳ
                'repair',               // Sửa chữa
                'inspection',           // Kiểm tra
                'registration',         // Đăng ký
                'insurance',            // Bảo hiểm
            ])->comment('Loại công việc');
            $table->enum('priority', [
                'urgent',   // Khẩn cấp
                'high',     // Ưu tiên cao
                'medium',   // Trung bình
                'low',      // Thấp
            ])->default('medium')->comment('Mức độ ưu tiên');

            // Nội dung
            $table->string('title')->comment('Tiêu đề công việc');
            $table->text('description')->nullable()->comment('Mô tả công việc chi tiết');
            $table->date('planned_date')->comment('Ngày dự kiến thực hiện');
            $table->unsignedSmallInteger('remind_before_days')->default(3)
                ->comment('Nhắc trước N ngày so với planned_date');

            // Chi phí
            $table->unsignedBigInteger('estimated_cost')->nullable()->comment('Chi phí dự kiến (VNĐ)');
            $table->unsignedBigInteger('actual_cost')->nullable()->comment('Chi phí thực tế (VNĐ)');

            // Thực hiện
            $table->string('garage')->nullable()->comment('Garage / Xưởng sửa chữa');
            $table->string('technician')->nullable()->comment('Kỹ thuật viên phụ trách');
            $table->decimal('km_at_service', 10, 1)->nullable()
                ->comment('Km đồng hồ tại thời điểm BDSC');
            $table->date('next_service_date')->nullable()
                ->comment('Ngày BDSC tiếp theo (do kỹ thuật viên xác nhận khi hoàn thành)');
            $table->text('notes')->nullable();

            // Trạng thái
            $table->enum('status', [
                'pending',     // Chờ thực hiện
                'in_progress', // Đang thực hiện
                'completed',   // Hoàn thành
                'cancelled',   // Hủy
                'overdue',     // Quá hạn
            ])->default('pending');
            $table->datetime('completed_at')->nullable();

            // Liên kết lịch tự động (nếu sinh từ schedule)
            $table->foreignId('schedule_id')
                ->nullable()
                ->constrained('vehicle_maintenance_schedules')
                ->nullOnDelete()
                ->comment('Sinh từ lịch tự động nào (null nếu tạo thủ công)');

            $table->foreignId('created_by')
                ->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['vehicle_id', 'status']);
            $table->index(['vehicle_id', 'planned_date']);
            $table->index('job_type');
            $table->index('priority');
            $table->index('planned_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_maintenance_jobs');
    }
};
