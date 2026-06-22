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
        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->foreignId('driver_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('delivery_point_id')
                ->comment('Lái xe ghi nhận');

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('driver_shifts')
                ->nullOnDelete()
                ->after('driver_id')
                ->comment('Ca trực liên quan');
        });
    }

    public function down(): void
    {
        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->dropForeign(['driver_id']);
            $table->dropForeign(['shift_id']);
            $table->dropColumn(['driver_id', 'shift_id']);
        });
    }
};
