<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['order_type_id', 'order_category_id']);
            $table->dropForeign(['order_type_id']);
            $table->dropColumn('order_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('order_type_id')
                ->constrained('order_types')
                ->after('type');
            $table->index(['order_type_id', 'order_category_id']);
        });
    }
};
