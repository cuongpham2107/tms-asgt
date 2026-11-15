<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (! Schema::hasColumn('locations', 'geofence_type')) {
                $table->string('geofence_type', 20)->default('circle')->after('geofence_radius_m');
            }
            if (! Schema::hasColumn('locations', 'geofence_bounds')) {
                $table->json('geofence_bounds')->nullable()->after('geofence_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            if (Schema::hasColumn('locations', 'geofence_bounds')) {
                $table->dropColumn('geofence_bounds');
            }
            if (Schema::hasColumn('locations', 'geofence_type')) {
                $table->dropColumn('geofence_type');
            }
        });
    }
};
