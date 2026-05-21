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
        Schema::create('vehicle_maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->comment('Xe áp dụng lịch này');

            // Phân loại
            $table->enum('job_type', [
                'periodic_maintenance',
                'repair',
                'inspection',
            ])->comment('Loại công việc sẽ tạo khi đến hạn');
            $table->enum('priority', ['urgent', 'high', 'medium', 'low'])
                ->default('medium');
            $table->string('name')->comment('Tên lịch nhắc, ví dụ: Thay dầu 5.000km');
            $table->text('description')->nullable();

            // Loại kích hoạt
            $table->enum('trigger_type', [
                'by_km',   // Chỉ theo Km
                'by_date', // Chỉ theo Ngày
                'both',    // Cả hai — cái nào đến trước
            ])->comment('Loại kích hoạt nhắc nhở');

            // === CẤU HÌNH THEO KM ===
            $table->unsignedInteger('km_interval')->nullable()
                ->comment('Chu kỳ bảo dưỡng (km), ví dụ: 5000');
            $table->decimal('km_current', 10, 1)->nullable()
                ->comment('Km hiện tại tại thời điểm cấu hình (snapshot)');
            $table->decimal('km_next_trigger', 10, 1)->nullable()
                ->comment('Mốc km sẽ kích hoạt nhắc = km_current + km_interval (hoặc do user nhập)');
            $table->unsignedInteger('km_remind_before')->nullable()->default(500)
                ->comment('Nhắc trước khi còn N km đến mốc, ví dụ: 500 km');

            // === CẤU HÌNH THEO NGÀY ===
            $table->unsignedSmallInteger('date_interval_days')->nullable()
                ->comment('Chu kỳ theo ngày, ví dụ: 90 (~3 tháng)');
            $table->date('last_service_date')->nullable()
                ->comment('Ngày BDSC lần cuối — mốc gốc tính ngày tiếp theo');
            $table->date('date_next_trigger')->nullable()
                ->comment('Ngày nhắc tiếp theo = last_service_date + date_interval_days');
            $table->unsignedSmallInteger('date_remind_before_days')->nullable()->default(14)
                ->comment('Cảnh báo trước N ngày so với date_next_trigger');

            // === CHI PHÍ & THỰC HIỆN ===
            $table->unsignedBigInteger('estimated_cost')->nullable()
                ->comment('Chi phí dự kiến mỗi lần BDSC (VNĐ)');
            $table->string('garage')->nullable()
                ->comment('Garage / Xưởng mặc định');

            // === FLAGS ===
            $table->boolean('is_mandatory')->default(false)
                ->comment('Bắt buộc — hiển thị cảnh báo đỏ khi quá hạn');
            $table->boolean('auto_create_job')->default(false)
                ->comment('Tự động tạo maintenance_job khi đến hạn nhắc');
            $table->boolean('is_active')->default(true);

            // Trạng thái cảnh báo hiện tại (cập nhật bởi scheduled job)
            $table->enum('alert_status', [
                'ok',       // Chưa đến hạn nhắc
                'warning',  // Trong vùng nhắc (còn N km / N ngày)
                'due',      // Đúng hạn / quá hạn
                'overdue',  // Quá hạn (is_mandatory=true → đỏ)
            ])->default('ok');
            $table->datetime('last_triggered_at')->nullable()
                ->comment('Lần cuối schedule này kích hoạt (tạo job / gửi cảnh báo)');

            $table->foreignId('created_by')
                ->constrained('users');
            $table->timestamps();

            $table->index(['vehicle_id', 'is_active']);
            $table->index(['vehicle_id', 'alert_status']);
            $table->index('trigger_type');
            $table->index('date_next_trigger');
            $table->index('km_next_trigger');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_maintenance_schedules');
    }
};
