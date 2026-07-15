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
        Schema::dropIfExists('empty_kilometers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('empty_kilometers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->comment('Lái xe ghi nhận');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->comment('Xe đang chạy (nếu có)');
            $table->foreignId('shift_id')->nullable()->constrained('driver_shifts')->comment('Ca trực liên quan');
            $table->decimal('start_km', 8, 1)->comment('Km bắt đầu');
            $table->decimal('end_km', 8, 1)->comment('Km kết thúc');
            $table->decimal('distance', 8, 1)->nullable()->comment('Km không hàng = end_km - start_km');
            $table->decimal('start_gps_lat', 10, 7)->nullable()->comment('Vĩ độ điểm bắt đầu');
            $table->decimal('start_gps_lng', 10, 7)->nullable()->comment('Kinh độ điểm bắt đầu');
            $table->decimal('end_gps_lat', 10, 7)->nullable()->comment('Vĩ độ điểm kết thúc');
            $table->decimal('end_gps_lng', 10, 7)->nullable()->comment('Kinh độ điểm kết thúc');
            $table->dateTime('started_at')->comment('Thời điểm bắt đầu');
            $table->dateTime('ended_at')->comment('Thời điểm kết thúc');
            $table->string('note')->nullable()->comment('Ghi chú');
            $table->timestamps();
        });
    }
};
