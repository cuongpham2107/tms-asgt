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

        // ── Orders: thêm sender/receiver (cho Hàng ngoài) ──────────
        Schema::table('orders', function (Blueprint $table) {
            // Hàng ngoài — người gửi
            $table->string('sender_name')->nullable()->after('cargo_type');
            $table->string('sender_contact')->nullable()->after('sender_name');
            $table->string('sender_phone', 20)->nullable()->after('sender_contact');

            // Hàng ngoài — người nhận
            $table->string('receiver_name')->nullable()->after('sender_phone');
            $table->string('receiver_contact')->nullable()->after('receiver_name');
            $table->string('receiver_phone', 20)->nullable()->after('receiver_contact');

            // Đội số liệu bổ sung
            $table->unsignedInteger('data_cargo_units')->nullable()->after('receiver_phone')->comment('Kiện (đội SL nhập)');
            $table->decimal('data_cargo_weight', 10, 2)->nullable()->after('data_cargo_units')->comment('Cân kg (đội SL nhập)');

            // Tính cước
            $table->decimal('freight_rate', 12, 2)->nullable()->after('data_cargo_weight')->comment('Đơn giá cước');
            $table->decimal('surcharges', 12, 2)->nullable()->after('freight_rate')->comment('Phụ phí');
            $table->decimal('total_cost', 12, 2)->nullable()->after('surcharges')->comment('Tổng chi phí');
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

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'sender_name', 'sender_contact', 'sender_phone',
                'receiver_name', 'receiver_contact', 'receiver_phone',
                'data_cargo_units', 'data_cargo_weight',
                'freight_rate', 'surcharges', 'total_cost',
            ]);
        });
    }
};
