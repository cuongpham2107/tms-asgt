<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mỗi chuyến chỉ có tối đa 1 đơn (chuyến rỗng/quay về có trip_id NULL ở phía đơn nên không bị chặn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['trip_id']);
            $table->unique('trip_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['trip_id']);
            $table->index('trip_id');
        });
    }
};
