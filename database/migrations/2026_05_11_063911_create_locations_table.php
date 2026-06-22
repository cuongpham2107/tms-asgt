<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('Viết tắt địa điểm, ví dụ: NBA, TN, BN');
            $table->string('name')->comment('Tên đầy đủ');
            $table->text('address')->nullable()->comment('Địa chỉ cụ thể');
            $table->decimal('lat', 10, 7)->nullable()->comment('Vĩ độ (latitude)');
            $table->decimal('lng', 10, 7)->nullable()->comment('Kinh độ (longitude)');
            $table->enum('loc_type', ['pickup', 'delivery', 'warehouse', 'other'])
                ->default('warehouse')
                ->comment('Loại địa điểm');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('code');
            $table->index('loc_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
