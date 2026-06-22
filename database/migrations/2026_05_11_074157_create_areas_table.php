<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['HHHK', 'external'])->default('HHHK')
                ->comment('Loại đơn: HHHK hoặc Hàng ngoài');
            $table->string('code', 30)->comment('Mã phân nhánh, ví dụ: NBA, TN, BN, NBO, province');
            $table->string('name')->comment('Tên hiển thị, ví dụ: Nội bộ A, Tây Nam, Bắc Nam...');
            $table->string('color', 20)->nullable()->comment('Màu badge riêng cho category này');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'code']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
