<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Danh mục phí gắn được với khách hàng + cách tính và đơn giá.
 * Phí không có khách = dùng chung, nhập tay như trước. Mã phí là "nhóm cột" trong bảng kê nên được trùng
 * (VD: SAFO có nhiều mức "rớt điểm" cùng mã extra_drop).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fee_types', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->index('code');
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('calc_method', 30)->default('manual')->after('name');
            $table->decimal('amount', 14, 2)->nullable()->after('calc_method');
            $table->boolean('auto_apply')->default(false)->after('amount');
            $table->text('description')->nullable()->after('auto_apply');
        });

        Schema::table('order_charge_fees', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->nullable()->after('amount');
            $table->boolean('is_auto')->default(false)->after('note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_charge_fees', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'is_auto']);
        });

        Schema::table('fee_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['calc_method', 'amount', 'auto_apply', 'description']);
            $table->dropIndex(['code']);
            $table->unique('code');
        });
    }
};
