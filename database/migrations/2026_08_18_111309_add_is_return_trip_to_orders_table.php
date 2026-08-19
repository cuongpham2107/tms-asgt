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
        if (! Schema::hasColumn('orders', 'is_return_trip')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->boolean('is_return_trip')
                    ->default(false)
                    ->comment('Là chuyến quay đầu');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'is_return_trip')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('is_return_trip');
            });
        }
    }
};
