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
        Schema::create('order_delivery_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete()
                ->comment('Điểm đến trong danh mục');
            $table->string('address')->nullable()->comment('Địa chỉ giao hàng (manual)');
            $table->string('contact_person')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->integer('total_packages')->nullable()->comment('Số kiện giao tại điểm này');
            $table->decimal('total_weight', 10, 2)->nullable()->comment('Trọng lượng giao tại điểm này (kg)');
            $table->unsignedTinyInteger('sequence')->default(1)->comment('Thứ tự giao hàng');
            $table->enum('status', ['pending', 'arrived', 'delivered'])->default('pending');
            $table->datetime('arrived_at')->nullable();
            $table->datetime('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'sequence']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_delivery_points');
    }
};
