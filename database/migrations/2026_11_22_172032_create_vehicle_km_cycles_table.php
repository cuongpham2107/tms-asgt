<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lưu cố định km của từng xe theo chu kỳ chốt 12:00 ngày 23 (KM over), để đối chiếu sau khi job ghi đè km đầu kỳ.
     */
    public function up(): void
    {
        Schema::create('vehicle_km_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->dateTime('cycle_start')->comment('Đầu kỳ (12:00 ngày 23 tháng trước)');
            $table->dateTime('cycle_end')->comment('Cuối kỳ: lúc job chốt chạy');
            $table->decimal('start_km', 12, 1)->comment('Km đầu kỳ (monthly_start_km trước khi chốt)');
            $table->decimal('end_km', 12, 1)->comment('Km cuối kỳ = km đầu kỳ mới (xe đang chạy chuyến lấy start_km của chuyến)');
            $table->decimal('km_run', 12, 1)->comment('Km đã chạy trong kỳ, như cột KM over');
            $table->boolean('tracks_km_over')->comment('Xe công ty không thuộc ASGT: có tính KM over');
            $table->unsignedInteger('km_limit')->comment('Định mức km/kỳ tại lúc chốt');
            $table->decimal('over_km', 12, 1)->default(0);
            $table->unsignedInteger('over_fee_per_km')->comment('Đơn giá vượt (VNĐ/km) tại lúc chốt');
            $table->unsignedBigInteger('over_fee')->default(0)->comment('Tiền vượt định mức (VNĐ)');
            $table->string('vehicle_owner')->nullable()->comment('Chủ xe tại lúc chốt');
            $table->timestamps();

            $table->unique(['vehicle_id', 'cycle_end']);
            $table->index('cycle_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_km_cycles');
    }
};
