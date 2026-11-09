<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('draft', 'assigned', 'sent', 'in_transit', 'driver_swap', 'completed', 'cancelled'))");

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [
                'draft',
                'assigned',
                'sent',
                'in_transit',
                'driver_swap',
                'completed',
                'cancelled',
            ])->default('draft')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('draft', 'assigned', 'sent', 'completed', 'cancelled'))");

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [
                'draft',
                'assigned',
                'sent',
                'completed',
                'cancelled',
            ])->default('draft')->change();
        });
    }
};
