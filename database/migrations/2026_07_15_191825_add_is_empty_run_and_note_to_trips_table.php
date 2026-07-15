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
        Schema::table('trips', function (Blueprint $table) {
            $table->boolean('is_empty_run')
                ->default(false)
                ->after('status')
                ->comment('Chuyến không hàng');
            $table->text('note')
                ->nullable()
                ->after('is_empty_run')
                ->comment('Ghi chú chuyến đi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['is_empty_run', 'note']);
        });
    }
};
