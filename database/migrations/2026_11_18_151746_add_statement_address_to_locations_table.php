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
        if (! Schema::hasColumn('locations', 'statement_address')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->text('statement_address')->nullable()->after('address');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('locations', 'statement_address')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('statement_address');
            });
        }
    }
};
