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
        if (! Schema::hasColumn('trips', 'created_by')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->foreignId('created_by')->nullable()->after('shift_id')->constrained('users')->nullOnDelete();
                $table->index('created_by');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('trips', 'created_by')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->dropConstrainedForeignId('created_by');
            });
        }
    }
};
