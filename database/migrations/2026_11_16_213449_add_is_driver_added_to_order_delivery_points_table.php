<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_delivery_points', function (Blueprint $table) {
            if (! Schema::hasColumn('order_delivery_points', 'is_driver_added')) {
                $table->boolean('is_driver_added')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_delivery_points', function (Blueprint $table) {
            if (Schema::hasColumn('order_delivery_points', 'is_driver_added')) {
                $table->dropColumn('is_driver_added');
            }
        });
    }
};
