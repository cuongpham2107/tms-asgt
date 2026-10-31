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
        Schema::create('waiting_fee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('surcharge_type', 100)->default('')->comment('Loại hình tính phụ phí, rỗng = chuẩn');
            $table->string('waiting_mode', 20)->default('normal')->comment('normal | engine_on (nổ máy) | engine_off (không nổ máy)');
            $table->json('tiers')->comment('Theo trọng tải: [{"tonnage": 5, "fee": 100000, "free_minutes": 120}]');
            $table->unsignedSmallInteger('fee_block_minutes')->default(60)->comment('Độ dài 1 block tính phí (phút)');
            $table->unsignedSmallInteger('grace_minutes')->default(0)->comment('Thời gian miễn thêm: phút lẻ vượt mức này mới tính thêm 1 block');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'surcharge_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waiting_fee_rates');
    }
};
