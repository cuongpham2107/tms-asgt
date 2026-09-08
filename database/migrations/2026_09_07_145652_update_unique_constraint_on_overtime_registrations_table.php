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
        Schema::table('overtime_registrations', function (Blueprint $table) {
            $table->dropUnique(['driver_id', 'overtime_date']);
            $table->unique(['driver_id', 'overtime_date', 'shift_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('overtime_registrations', function (Blueprint $table) {
            $table->dropUnique(['driver_id', 'overtime_date', 'shift_type']);
            $table->unique(['driver_id', 'overtime_date']);
        });
    }
};
