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
        Schema::create('vehicle_gps_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('speed', 6, 1)->nullable();
            $table->unsignedSmallInteger('direction')->nullable();
            $table->unsignedTinyInteger('car_status')->nullable()->comment('EUP CarStatus: 0=Đang hoạt động, 2=Dừng xe, 5=Mất tín hiệu, 10=Dừng không tắt máy');
            $table->string('address')->nullable();
            $table->dateTime('recorded_at')->comment('Thời điểm thiết bị EUP ghi nhận (OperateDate)');
            $table->timestamp('created_at')->nullable();

            $table->index(['vehicle_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_gps_logs');
    }
};
