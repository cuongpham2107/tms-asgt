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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_number', 20)->unique()->comment('Biển số xe');
            $table->enum('vehicle_type', [
                'normal',       // xe thường
                'cold',         // xe lạnh
                'anti_vibration', // xe chống rung
                'container',    // xe cont
                'flatbed',      // xe fooc
                'bat_wing',     // cánh dơi
                'other',
            ])->default('normal')->comment('Loại xe');
            $table->string('owner')->comment('Chủ xe: ASGT|Tam Bảo|HMA|VT123|Hải Như|ACE|CBT|...');
            $table->string('make')->nullable()->comment('Hãng xe');
            $table->year('model_year')->nullable()->comment('Năm sản xuất');
            $table->decimal('load_capacity', 8, 2)->nullable()->comment('Tải trọng (tấn)');
            $table->string('fuel_type')->nullable()->comment('Loại nhiên liệu');
            $table->decimal('current_mileage', 10, 2)->nullable()->comment('Số km hiện tại');
            $table->foreignId('current_driver_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Lái xe hiện tại đang lái xe này (từ app vào ca)');
            $table->boolean('is_active')->default(true);
            $table->enum('status', [
                'on',   // sẵn sàng,
                'off',  // tắt,
                'bdsc', // bảo dưỡng sửa chữa
                'running', // đang chạy
            ])->default('on');
            $table->enum('type', [
                'company',  // Xe công ty
                'rent', // Xe thuê ngoài
            ])->default('company'); // phân loại
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('plate_number');
            $table->index('owner');
            $table->index('vehicle_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
