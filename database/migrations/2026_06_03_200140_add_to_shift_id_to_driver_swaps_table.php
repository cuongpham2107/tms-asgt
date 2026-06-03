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
            $table->foreignId('to_shift_id')
                ->nullable()
                ->constrained('driver_shifts')
                ->nullOnDelete()
                ->after('from_shift_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_swaps', function (Blueprint $table) {
            $table->dropForeign(['to_shift_id']);
            $table->dropColumn('to_shift_id');
        });
    }
};
