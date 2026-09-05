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
        Schema::create('overtime_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete()->comment('Tài xế đăng ký');
            $table->string('shift_type')->comment('Loại ca tăng cường');
            $table->date('overtime_date')->comment('Ngày tăng cường');
            $table->string('status')->default('pending')->comment('Trạng thái: pending, confirmed, rejected');
            $table->timestamp('confirmed_at')->nullable()->comment('Thời điểm xác nhận/từ chối');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete()->comment('Admin xác nhận');
            $table->text('notes')->nullable()->comment('Ghi chú');
            $table->timestamps();

            $table->unique(['driver_id', 'overtime_date']);
            $table->index('overtime_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_registrations');
    }
};
