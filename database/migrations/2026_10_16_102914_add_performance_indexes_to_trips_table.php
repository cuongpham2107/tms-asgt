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
            $table->index('status');
            $table->index('started_at');
            $table->index('vehicle_id');
            $table->index('driver_id');
            $table->index(['status', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['started_at']);
            $table->dropIndex(['vehicle_id']);
            $table->dropIndex(['driver_id']);
            $table->dropIndex(['status', 'started_at']);
        });
    }
};
