<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_delivery_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->integer('total_packages')->nullable();
            $table->decimal('total_weight', 10, 2)->nullable();
            $table->smallInteger('sequence')->default(1);
            $table->string('status')->default('pending');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'sequence']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_points');
    }
};
