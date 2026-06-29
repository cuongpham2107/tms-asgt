<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
