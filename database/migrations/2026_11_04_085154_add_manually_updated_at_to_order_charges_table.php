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
        Schema::table('order_charges', function (Blueprint $table) {
            $table->timestamp('manually_updated_at')->nullable()->after('exported_by')->comment('Thời điểm người dùng chỉnh sửa cước trên form');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_charges', function (Blueprint $table) {
            $table->dropColumn('manually_updated_at');
        });
    }
};
