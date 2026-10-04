<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Đảo lái giờ được ghi trong trip_driver_assignments (đã backfill ở migration trước).
     */
    public function up(): void
    {
        Schema::dropIfExists('driver_swaps');
    }

    public function down(): void
    {
        Schema::create('driver_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_driver_id')->nullable()->constrained('users');
            $table->foreignId('to_driver_id')->nullable()->constrained('users');
            $table->foreignId('from_shift_id')->nullable()->constrained('driver_shifts');
            $table->foreignId('to_shift_id')->nullable()->constrained('driver_shifts');
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();
        });
    }
};
