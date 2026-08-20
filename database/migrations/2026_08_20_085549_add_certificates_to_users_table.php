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
        Schema::table('users', function (Blueprint $table) {
            $table->string('aviation_security_cert_number')->nullable()->after('license_image');
            $table->date('aviation_security_cert_issue_date')->nullable()->after('aviation_security_cert_number');
            $table->date('aviation_security_cert_expiry_date')->nullable()->after('aviation_security_cert_issue_date');
            $table->string('aviation_security_cert_image')->nullable()->after('aviation_security_cert_expiry_date');

            $table->string('dangerous_goods_cert_number')->nullable()->after('aviation_security_cert_image');
            $table->date('dangerous_goods_cert_issue_date')->nullable()->after('dangerous_goods_cert_number');
            $table->date('dangerous_goods_cert_expiry_date')->nullable()->after('dangerous_goods_cert_issue_date');
            $table->string('dangerous_goods_cert_image')->nullable()->after('dangerous_goods_cert_expiry_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'aviation_security_cert_number',
                'aviation_security_cert_issue_date',
                'aviation_security_cert_expiry_date',
                'aviation_security_cert_image',
                'dangerous_goods_cert_number',
                'dangerous_goods_cert_issue_date',
                'dangerous_goods_cert_expiry_date',
                'dangerous_goods_cert_image',
            ]);
        });
    }
};
