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
            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete()
                ->comment('Chuyến xe');
            $table->foreignId('from_driver_id')
                ->constrained('users')
                ->comment('Lái xe cũ');
            $table->foreignId('to_driver_id')
                ->constrained('users')
                ->comment('Lái xe mới');
            $table->foreignId('from_shift_id')
                ->constrained('driver_shifts')
                ->comment('Ca của lái xe cũ');
            $table->foreignId('to_shift_id')
                ->nullable()
                ->constrained('driver_shifts')
                ->nullOnDelete()
                ->comment('Ca của lái xe mới');
            $table->decimal('handover_km', 10, 1)->nullable()
                ->comment('Km bàn giao = km kết thúc của lái cũ = km bắt đầu của lái mới');
            $table->enum('reason', [
                'shift_handover',
                'cargo_not_unloaded',
                'other',
            ])->comment('Lý do đảo lái');
            $table->text('note')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')
                ->comment('Điều hành thực hiện đảo lái');
            $table->timestamp('created_at')->useCurrent();

            $table->index('trip_id');
            $table->index('from_driver_id');
            $table->index('to_driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_swaps');
    }
};
