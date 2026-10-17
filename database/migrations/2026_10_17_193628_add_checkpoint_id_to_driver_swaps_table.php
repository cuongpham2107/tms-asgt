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
        Schema::table('driver_swaps', function (Blueprint $table) {
            $table->foreignId('checkpoint_id')
                ->nullable()
                ->after('trip_id')
                ->constrained('trip_checkpoints')
                ->nullOnDelete()
                ->comment('Mốc driver_swap chứa km/giờ bàn giao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_swaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkpoint_id');
        });
    }
};
