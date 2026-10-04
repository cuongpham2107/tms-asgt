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
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['start_km', 'end_km']);
        });

        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->dropColumn(['start_km', 'end_km']);
        });

        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->dropColumn('km_reading');
        });

        Schema::table('driver_swaps', function (Blueprint $table) {
            $table->dropColumn('handover_km');
        });

        Schema::dropIfExists('trip_km_reports');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('trip_km_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->constrained('trip_checkpoints')->nullOnDelete();
            $table->foreignId('driver_id')->constrained('users');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->decimal('reported_km', 10, 1);
            $table->decimal('system_km', 10, 1)->nullable();
            $table->string('photo_path')->nullable();
            $table->text('note')->nullable();
            $table->string('status')->default('pending');
            $table->text('admin_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'status']);
        });

        Schema::table('driver_swaps', function (Blueprint $table) {
            $table->decimal('handover_km', 10, 1)->nullable();
        });

        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->decimal('km_reading', 10, 1)->nullable();
        });

        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('start_km', 10, 1)->nullable();
            $table->decimal('end_km', 10, 1)->nullable();
        });
    }
};
