<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'geofence_radius_m')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->unsignedInteger('geofence_radius_m')->nullable()->after('lng');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('locations', 'geofence_radius_m')) {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropColumn('geofence_radius_m');
            });
        }
    }
};
