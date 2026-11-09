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
            $table->string('plate_number', 20)->unique();
            $table->string('registration_number', 30)->nullable();
            $table->enum('vehicle_type', [
                'normal', 'cold', 'anti_vibration', 'container', 'flatbed', 'bat_wing', 'other',
            ])->default('normal');
            $table->string('owner');
            $table->string('make')->nullable();
            $table->integer('model_year')->nullable();
            $table->decimal('load_capacity', 8, 2)->nullable();
            $table->smallInteger('door_count')->nullable();
            $table->decimal('total_weight', 8, 2)->nullable();
            $table->decimal('cargo_volume', 10, 2)->nullable();
            $table->integer('box_length')->nullable();
            $table->integer('box_width')->nullable();
            $table->integer('box_height')->nullable();
            $table->string('fuel_type')->nullable();
            $table->decimal('current_mileage', 10, 2)->nullable();
            $table->foreignId('current_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->decimal('gps_speed', 8, 2)->nullable();
            $table->smallInteger('gps_direction')->nullable();
            $table->string('gps_address', 500)->nullable();
            $table->timestamp('last_gps_update')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['on', 'off', 'bdsc', 'running'])->default('on');
            $table->string('off_reason')->nullable();
            $table->enum('type', ['company', 'rent'])->default('company');
            $table->text('notes')->nullable();
            $table->string('dangerous_goods_permit_number')->nullable();
            $table->date('dangerous_goods_permit_issue_date')->nullable();
            $table->date('dangerous_goods_permit_expiry_date')->nullable();
            $table->string('dangerous_goods_permit_image')->nullable();
            $table->foreignId('even_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('odd_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('car_status')->nullable();
            $table->decimal('monthly_start_km', 10, 2)->nullable();
            $table->timestamp('idle_engine_since')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('plate_number');
            $table->index('status');
            $table->index('type');
            $table->index('is_active');
        });

        // Thiết lập Foreign Key từ bảng users trỏ sang vehicles
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['vehicle_id']);
        });

        Schema::dropIfExists('vehicles');
    }
};
