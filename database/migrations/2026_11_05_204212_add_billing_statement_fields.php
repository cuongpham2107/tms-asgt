<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loại phí mới cho bảng kê (thêm nếu chưa có).
     *
     * @var array<string, string>
     */
    private const NEW_FEE_TYPES = [
        'overnight_storage' => 'Chi phí lưu đêm/ngày',
        'holiday' => 'Chi phí ngày lễ tết',
        'highway_toll' => 'Chi phí vé cao tốc',
        'city_entry' => 'Phí vào phố',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_code', 50)->nullable()->after('address');
            $table->decimal('fuel_adjustment_percent', 5, 2)->default(0)->after('tax_code');
            $table->decimal('cancel_trip_percent', 5, 2)->default(0)->after('fuel_adjustment_percent');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('bill_number')->nullable()->after('notes');
            $table->unsignedInteger('pallet_count')->nullable()->after('bill_number');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->string('old_address')->nullable()->after('address');
        });

        Schema::table('order_charges', function (Blueprint $table) {
            $table->boolean('is_cancelled_trip')->default(false)->after('return_trip_percent');
            $table->decimal('cancel_trip_percent', 5, 2)->default(0)->after('is_cancelled_trip');
        });

        $now = now();
        $sort = (int) DB::table('fee_types')->max('sort');
        foreach (self::NEW_FEE_TYPES as $code => $name) {
            DB::table('fee_types')->insertOrIgnore([
                'code' => $code,
                'name' => $name,
                'sort' => ++$sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('fee_types')
            ->whereIn('code', array_keys(self::NEW_FEE_TYPES))
            ->whereNotExists(fn ($query) => $query->from('order_charge_fees')->whereColumn('order_charge_fees.fee_type_id', 'fee_types.id'))
            ->delete();

        Schema::table('order_charges', function (Blueprint $table) {
            $table->dropColumn(['is_cancelled_trip', 'cancel_trip_percent']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('old_address');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['bill_number', 'pallet_count']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['tax_code', 'fuel_adjustment_percent', 'cancel_trip_percent']);
        });
    }
};
