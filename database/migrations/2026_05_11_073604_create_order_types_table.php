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
        Schema::create('order_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('Mã loại, ví dụ: HHHK, external');
            $table->string('name')->comment('Tên hiển thị, ví dụ: Hàng hàng không, Hàng ngoài');
            $table->string('color', 20)->nullable()->comment('Màu badge hiển thị UI, ví dụ: blue, green');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_types');
    }
};
