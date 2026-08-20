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
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('dangerous_goods_permit_number')->nullable()->after('notes');
            $table->date('dangerous_goods_permit_issue_date')->nullable()->after('dangerous_goods_permit_number');
            $table->date('dangerous_goods_permit_expiry_date')->nullable()->after('dangerous_goods_permit_issue_date');
            $table->string('dangerous_goods_permit_image')->nullable()->after('dangerous_goods_permit_expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'dangerous_goods_permit_number',
                'dangerous_goods_permit_issue_date',
                'dangerous_goods_permit_expiry_date',
                'dangerous_goods_permit_image',
            ]);
        });
    }
};
