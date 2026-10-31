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
        Schema::create('order_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('freight_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('price_code')->nullable()->comment('Mã giá (snapshot)');

            $table->string('cargo_type', 10)->nullable()->comment('Loại hàng');
            $table->string('rate_type', 100)->default('')->comment('Loại hình tính cước');
            $table->string('surcharge_type', 100)->default('')->comment('Loại hình tính phụ phí');
            $table->decimal('chargeable_tonnage', 5, 2)->nullable()->comment('Trọng tải tính cước (tấn)');
            $table->boolean('is_return_trip')->default(false)->comment('Chuyến quay đầu');
            $table->decimal('return_trip_percent', 5, 2)->default(50)->comment('% đơn giá áp cho chuyến quay đầu');

            $table->json('delivery_notes')->nullable()->comment('Phiếu giao hàng (tách được nhiều phiếu): [{"number": "PGH-01", "actual_weight": 7000, "chargeable_weight": 7000}]');
            $table->text('note')->nullable();

            $table->decimal('base_price', 14, 2)->nullable()->comment('Đơn giá vận chuyển gốc');
            $table->decimal('fuel_adjustment_percent', 6, 2)->default(0)->comment('Tỷ lệ tăng/giảm dầu (%)');
            $table->decimal('adjusted_price', 14, 2)->nullable()->comment('Đơn giá sau điều chỉnh (làm tròn nghìn)');
            $table->unsignedInteger('waiting_minutes')->default(0)->comment('Thời gian chờ (phút) = đến lấy hàng → rời lấy hàng + đến điểm trả → hoàn thành');
            $table->decimal('waiting_fee', 14, 2)->default(0)->comment('Chi phí giờ chờ');
            $table->decimal('other_fees_total', 14, 2)->default(0)->comment('Tổng các phí khác');
            $table->decimal('subtotal', 14, 2)->default(0)->comment('Tổng trước VAT');
            $table->decimal('vat_percent', 5, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0)->comment('Thanh toán');

            $table->dateTime('exported_at')->nullable()->comment('Đã xuất bảng kê → khoá sửa');
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'rate_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_charges');
    }
};
