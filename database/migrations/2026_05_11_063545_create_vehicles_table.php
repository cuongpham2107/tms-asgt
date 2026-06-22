<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_number', 20)->unique()->comment('Biển số xe');
            $table->string('registration_number', 30)->nullable()->after('plate_number')->comment('Số đăng ký xe');
            $table->enum('vehicle_type', [
                'normal', 'cold', 'anti_vibration', 'container', 'flatbed', 'bat_wing', 'other',
            ])->default('normal')->comment('Loại xe');
            $table->string('owner')->comment('Chủ xe: ASGT|Tam Bảo|HMA|VT123|Hải Như|ACE|CBT|...');
            $table->string('make')->nullable()->comment('Hãng xe');
            $table->year('model_year')->nullable()->comment('Năm sản xuất');
            $table->decimal('load_capacity', 8, 2)->nullable()->comment('Tải trọng (tấn)');
            $table->unsignedTinyInteger('door_count')->nullable()->comment('Số cửa');
            $table->decimal('total_weight', 8, 2)->nullable()->comment('Tổng trọng tải (tấn)');
            $table->decimal('cargo_volume', 10, 2)->nullable()->comment('Số khối thực tế (m³)');
            $table->integer('box_length')->nullable()->comment('Dài (mm)');
            $table->integer('box_width')->nullable()->comment('Rộng (mm)');
            $table->integer('box_height')->nullable()->comment('Cao (mm)');
            $table->string('fuel_type')->nullable()->comment('Loại nhiên liệu');
            $table->decimal('current_mileage', 10, 2)->nullable()->comment('Số km hiện tại');
            $table->foreignId('current_driver_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('Lái xe hiện tại đang lái xe này (từ app vào ca)');
            $table->decimal('gps_lat', 10, 7)->nullable()->comment('Vĩ độ GPS hiện tại');
            $table->decimal('gps_lng', 10, 7)->nullable()->comment('Kinh độ GPS hiện tại');
            $table->decimal('gps_speed', 8, 2)->nullable()->comment('Tốc độ GPS hiện tại (km/h)');
            $table->smallInteger('gps_direction')->nullable()->comment('Hướng di chuyển (độ)');
            $table->string('gps_address', 500)->nullable()->comment('Địa chỉ GPS hiện tại');
            $table->dateTime('last_gps_update')->nullable()->comment('Thời điểm cập nhật GPS cuối');
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['on', 'off', 'bdsc', 'running'])->default('on');
            $table->string('off_reason')->nullable()->comment('Lý do OFF: BDSC / Đăng kiểm / Bất thường');
            $table->enum('type', ['company', 'rent'])->default('company');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('plate_number');
            $table->index('owner');
            $table->index('vehicle_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
