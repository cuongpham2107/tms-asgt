<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Khu vực tính cước (VD "KCN YÊN PHONG" gồm SEV, ALSB, SDV…), tách riêng với bảng areas dùng cho điều xe.
 * Đơn giá chuyển từ địa điểm sang khu vực: đơn giá cũ bị xoá — chạy lại `freight-rates:import` sau khi migrate.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique()->comment('Mã viết tắt, VD: KCN YÊN PHONG');
            $table->string('name')->comment('Tên khu vực');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('billing_zone_id')->nullable()->after('area_id')->constrained()->nullOnDelete()->comment('Khu vực tính cước');
        });

        DB::table('order_charges')->update(['freight_rate_id' => null]);
        DB::table('freight_rates')->delete();

        Schema::table('freight_rates', function (Blueprint $table) {
            $table->dropIndex('freight_rates_lookup_index');
            $table->dropConstrainedForeignId('origin_location_id');
            $table->dropConstrainedForeignId('destination_location_id');
        });

        Schema::table('freight_rates', function (Blueprint $table) {
            $table->foreignId('origin_zone_id')->after('customer_id')->constrained('billing_zones')->cascadeOnDelete()->comment('Khu vực điểm đi');
            $table->foreignId('destination_zone_id')->after('origin_zone_id')->constrained('billing_zones')->cascadeOnDelete()->comment('Khu vực điểm đến');
            $table->index(['customer_id', 'origin_zone_id', 'destination_zone_id', 'cargo_type'], 'freight_rates_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('order_charges')->update(['freight_rate_id' => null]);
        DB::table('freight_rates')->delete();

        Schema::table('freight_rates', function (Blueprint $table) {
            $table->dropIndex('freight_rates_lookup_index');
            $table->dropConstrainedForeignId('origin_zone_id');
            $table->dropConstrainedForeignId('destination_zone_id');
        });

        Schema::table('freight_rates', function (Blueprint $table) {
            $table->foreignId('origin_location_id')->after('customer_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignId('destination_location_id')->after('origin_location_id')->constrained('locations')->cascadeOnDelete();
            $table->index(['customer_id', 'origin_location_id', 'destination_location_id', 'cargo_type'], 'freight_rates_lookup_index');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_zone_id');
        });

        Schema::dropIfExists('billing_zones');
    }
};
