<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE trips DROP CONSTRAINT IF EXISTS trips_status_check');
            DB::statement("ALTER TABLE trips ADD CONSTRAINT trips_status_check CHECK (status IN ('pending', 'started', 'arrived_pickup', 'delivering', 'arrived_delivery', 'delivered', 'completed', 'driver_swap', 'cancelled', 'return_trip'))");

            return;
        }

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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE trips DROP CONSTRAINT IF EXISTS trips_status_check');
            DB::statement("ALTER TABLE trips ADD CONSTRAINT trips_status_check CHECK (status IN ('pending', 'started', 'arrived_pickup', 'delivering', 'arrived_delivery', 'delivered', 'completed', 'driver_swap', 'cancelled'))");

            return;
        }

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
