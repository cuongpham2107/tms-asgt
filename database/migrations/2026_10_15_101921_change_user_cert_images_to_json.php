<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Chuyển đổi dữ liệu ảnh cũ dạng string sang mảng JSON nếu có
        $users = DB::table('users')
            ->select('id', 'license_image', 'aviation_security_cert_image', 'dangerous_goods_cert_image')
            ->where(function ($q) {
                $q->whereNotNull('license_image')
                    ->orWhereNotNull('aviation_security_cert_image')
                    ->orWhereNotNull('dangerous_goods_cert_image');
            })
            ->get();

        foreach ($users as $user) {
            $updates = [];
            foreach (['license_image', 'aviation_security_cert_image', 'dangerous_goods_cert_image'] as $col) {
                $val = $user->{$col};
                if ($val && ! str_starts_with($val, '[')) {
                    $updates[$col] = json_encode([$val]);
                }
            }
            if (! empty($updates)) {
                DB::table('users')->where('id', $user->id)->update($updates);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->json('license_image')->nullable()->change();
            $table->json('aviation_security_cert_image')->nullable()->change();
            $table->json('dangerous_goods_cert_image')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('license_image')->nullable()->change();
            $table->string('aviation_security_cert_image')->nullable()->change();
            $table->string('dangerous_goods_cert_image')->nullable()->change();
        });
    }
};
