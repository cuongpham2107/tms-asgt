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
            $table->enum('status', [
                'pending',
                'started',
                'arrived_pickup',
                'delivering',
                'arrived_delivery',
                'delivered',
                'completed',
                'driver_swap',
                'cancelled',
            ])->default('pending')->change();

            $table->datetime('cancelled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');

            $table->enum('status', [
                'pending',
                'started',
                'arrived_pickup',
                'delivering',
                'arrived_delivery',
                'delivered',
                'completed',
                'driver_swap',
            ])->default('pending')->change();
        });
    }
};
