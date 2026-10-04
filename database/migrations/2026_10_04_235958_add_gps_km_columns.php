<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('km_source', 20)->nullable()->comment('phone_gps | eup | mixed | manual');
            $table->decimal('gps_coverage', 5, 2)->nullable()->comment('% thời gian chuyến có điểm GPS');
            $table->dateTime('km_calculated_at')->nullable();
            $table->boolean('km_needs_review')->default(false);
            $table->decimal('km_adjusted', 10, 1)->nullable();
            $table->decimal('km_adjusted_loaded', 10, 1)->nullable();
            $table->text('km_adjust_reason')->nullable();
            $table->foreignId('km_adjusted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('trip_driver_assignments', function (Blueprint $table) {
            $table->decimal('km', 10, 1)->nullable();
            $table->decimal('km_loaded', 10, 1)->nullable();
            $table->decimal('km_empty', 10, 1)->nullable();
        });

        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->dateTime('km_calculated_at')->nullable();
        });

        // Chuyến và ca đã đóng trước khi có GPS: giữ nguyên km cũ (từ đồng hồ), không để lệnh gps:calculate-km ghi đè thành 0.
        DB::table('trips')
            ->whereIn('status', ['completed', 'cancelled'])
            ->update(['km_calculated_at' => now(), 'km_source' => 'manual']);

        DB::table('driver_shifts')
            ->whereNotNull('end_time')
            ->update(['km_calculated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('km_adjusted_by');
            $table->dropColumn(['km_source', 'gps_coverage', 'km_calculated_at', 'km_needs_review', 'km_adjusted', 'km_adjusted_loaded', 'km_adjust_reason']);
        });

        Schema::table('trip_driver_assignments', function (Blueprint $table) {
            $table->dropColumn(['km', 'km_loaded', 'km_empty']);
        });

        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->dropColumn('km_calculated_at');
        });
    }
};
