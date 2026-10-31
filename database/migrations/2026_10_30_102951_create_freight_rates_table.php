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
        Schema::create('freight_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_location_id')->constrained('locations')->cascadeOnDelete()->comment('Điểm đi');
            $table->foreignId('destination_location_id')->constrained('locations')->cascadeOnDelete()->comment('Điểm đến');
            $table->string('cargo_type', 10)->default('GCR')->comment('Loại hàng: GCR | DGR');
            $table->string('rate_type', 100)->default('')->comment('Loại hình tính cước (KIOWAY 1, CLC/SDV, Hàng lạnh...), rỗng = chuẩn');
            $table->string('vehicle_type', 30)->nullable()->comment('Loại xe, null = mọi loại');
            $table->json('prices')->comment('Đơn giá VND/chuyến theo trọng tải tính cước: {"1.25": 750000, "5": 1500000}');
            $table->date('effective_from')->nullable()->comment('Áp dụng từ ngày');
            $table->date('effective_to')->nullable()->comment('Áp dụng đến ngày');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'origin_location_id', 'destination_location_id', 'cargo_type'], 'freight_rates_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('freight_rates');
    }
};
