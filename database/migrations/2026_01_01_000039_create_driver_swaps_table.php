<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips');
            $table->foreignId('from_driver_id')->constrained('users');
            $table->foreignId('to_driver_id')->constrained('users');
            $table->foreignId('from_shift_id')->nullable()->constrained('driver_shifts')->nullOnDelete();
            $table->foreignId('to_shift_id')->nullable()->constrained('driver_shifts')->nullOnDelete();
            $table->foreignId('checkpoint_id')->nullable()->constrained('trip_checkpoints')->nullOnDelete();
            $table->decimal('handover_km', 10, 1)->nullable();
            $table->enum('reason', ['shift_handover', 'cargo_not_unloaded', 'other']);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('trip_id');
            $table->index('from_driver_id');
            $table->index('to_driver_id');
            $table->index('checkpoint_id');
            $table->index(['to_driver_id', 'to_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_swaps');
    }
};
