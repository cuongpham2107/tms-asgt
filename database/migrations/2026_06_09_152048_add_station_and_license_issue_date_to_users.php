<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('station')->nullable()->after('certificates')->comment('Điểm trực');
            $table->date('license_issue_date')->nullable()->after('station')->comment('Năm cấp bằng');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['station', 'license_issue_date']);
        });
    }
};
