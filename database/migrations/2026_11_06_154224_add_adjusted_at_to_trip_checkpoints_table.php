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
        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->timestamp('adjusted_at')->nullable()->after('occurred_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->dropColumn('adjusted_at');
        });
    }
};
