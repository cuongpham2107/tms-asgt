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
        Schema::create('order_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_type_id')
                ->constrained('order_types')
                ->cascadeOnDelete()
                ->comment('Thuộc loại đơn nào');
            $table->string('code', 30)->comment('Mã phân nhánh, ví dụ: NBA, TN, BN, NBO, province');
            $table->string('name')->comment('Tên hiển thị, ví dụ: Nội bộ A, Tây Nam, Bắc Nam...');
            $table->string('color', 20)->nullable()->comment('Màu badge riêng cho category này');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // code phải unique trong cùng 1 order_type
            $table->unique(['order_type_id', 'code']);
            $table->index('order_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_categories');
    }
};
