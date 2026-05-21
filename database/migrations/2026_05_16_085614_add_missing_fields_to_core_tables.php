<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Users: thêm CCCD + chứng chỉ ────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->string('cccd', 20)->nullable()->after('license_number')->comment('Số CCCD');
            $table->date('cccd_issue_date')->nullable()->after('cccd')->comment('Ngày cấp CCCD');
            $table->json('certificates')->nullable()->after('cccd_issue_date')->comment('Chứng chỉ đi kèm');
        });

        // ── Vehicles: thêm registration_number, door_count, off_reason
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('registration_number', 30)->nullable()->after('plate_number')->comment('Số đăng ký xe');
            $table->unsignedTinyInteger('door_count')->nullable()->after('load_capacity')->comment('Số cửa');
            $table->string('off_reason')->nullable()->after('status')->comment('Lý do OFF: BDSC / Đăng kiểm / Bất thường');
        });


    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['cccd', 'cccd_issue_date', 'certificates']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['registration_number', 'door_count', 'off_reason']);
        });


    }
};
