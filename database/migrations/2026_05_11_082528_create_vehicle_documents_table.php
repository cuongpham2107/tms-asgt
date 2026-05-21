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
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')
                ->constrained('vehicles')
                ->comment('Xe sở hữu giấy tờ');

            $table->enum('doc_type', [
                'registration', // Đăng ký xe
                'inspection',   // Đăng kiểm
            ])->comment('Loại giấy tờ');

            $table->string('certificate_number')->comment('Số giấy chứng nhận');
            $table->string('issued_by')->comment('Cơ quan cấp');
            $table->date('issued_date')->comment('Ngày cấp');
            $table->date('expiry_date')->comment('Ngày hết hạn — dùng để tính cảnh báo');

            $table->unsignedBigInteger('renewal_cost')->nullable()
                ->comment('Chi phí gia hạn dự kiến (VNĐ)');
            $table->date('last_renewed_date')->nullable()
                ->comment('Ngày gia hạn gần nhất');

            $table->text('notes')->nullable();

            // Trạng thái — tính tự động dựa trên expiry_date so với today
            // active | expiring_soon (≤30 ngày) | expired
            $table->enum('status', ['active', 'expiring_soon', 'expired'])
                ->default('active')
                ->comment('Cập nhật bởi scheduled job hàng ngày');

            $table->foreignId('created_by')
                ->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['vehicle_id', 'doc_type', 'certificate_number'],
                'unique_vehicle_doc_cert');
            $table->index(['vehicle_id', 'doc_type']);
            $table->index('expiry_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
    }
};
