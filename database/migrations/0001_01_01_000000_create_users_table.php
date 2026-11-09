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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->date('date_of_birth')->nullable();
            $table->enum('license_class', ['B', 'B1', 'C1', 'C', 'FC', 'D', 'E'])->nullable();
            $table->string('license_number')->nullable();
            $table->date('license_expiry_date')->nullable();
            $table->json('license_image')->nullable();
            $table->string('cccd', 20)->nullable();
            $table->date('cccd_issue_date')->nullable();
            $table->json('certificates')->nullable();
            $table->string('station')->nullable();
            $table->date('license_issue_date')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('avatar')->nullable();
            $table->boolean('is_active')->default(false);
            $table->rememberToken();
            $table->timestamps();

            // Chứng chỉ an ninh hàng không & hàng nguy hiểm
            $table->string('aviation_security_cert_number')->nullable();
            $table->date('aviation_security_cert_issue_date')->nullable();
            $table->date('aviation_security_cert_expiry_date')->nullable();
            $table->json('aviation_security_cert_image')->nullable();
            $table->string('dangerous_goods_cert_number')->nullable();
            $table->date('dangerous_goods_cert_issue_date')->nullable();
            $table->date('dangerous_goods_cert_expiry_date')->nullable();
            $table->json('dangerous_goods_cert_image')->nullable();

            // Firebase push & ca làm việc & xe gắn kết
            $table->text('fcm_token')->nullable();
            $table->timestamp('fcm_token_updated_at')->nullable();
            $table->string('work_shift', 10)->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('employee_code')->nullable();

            $table->index('is_active');
            $table->index('station');
            $table->index('work_shift');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
