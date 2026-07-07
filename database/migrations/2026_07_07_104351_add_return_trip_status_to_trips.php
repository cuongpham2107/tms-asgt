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
                'return_trip',
            ])->default('pending')->change();
        });
    }

    public function down(): void
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
        });
    }
};
