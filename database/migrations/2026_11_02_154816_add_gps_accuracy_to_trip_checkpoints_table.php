<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trip_checkpoints', 'gps_accuracy')) {
            Schema::table('trip_checkpoints', function (Blueprint $table) {
                $table->unsignedInteger('gps_accuracy')->nullable()->after('gps_lng');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('trip_checkpoints', 'gps_accuracy')) {
            Schema::table('trip_checkpoints', function (Blueprint $table) {
                $table->dropColumn('gps_accuracy');
            });
        }
    }
};
