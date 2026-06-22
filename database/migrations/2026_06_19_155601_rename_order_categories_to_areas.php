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
        // 1. Rename table order_categories to areas
        Schema::rename('order_categories', 'areas');

        // 2. Rename column order_category_id to area_id in orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('order_category_id', 'area_id');
        });

        // 3. Add area_id column to locations table linking to areas
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('area_id')
                ->nullable()
                ->constrained('areas')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['area_id']);
            $table->dropColumn('area_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('area_id', 'order_category_id');
        });

        Schema::rename('areas', 'order_categories');
    }
};
