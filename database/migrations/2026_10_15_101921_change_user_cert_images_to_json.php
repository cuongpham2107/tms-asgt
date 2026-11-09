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

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ALTER COLUMN license_image TYPE json USING CASE WHEN license_image IS NULL THEN NULL WHEN license_image = '' THEN '[]'::json ELSE license_image::json END");
            DB::statement("ALTER TABLE users ALTER COLUMN aviation_security_cert_image TYPE json USING CASE WHEN aviation_security_cert_image IS NULL THEN NULL WHEN aviation_security_cert_image = '' THEN '[]'::json ELSE aviation_security_cert_image::json END");
            DB::statement("ALTER TABLE users ALTER COLUMN dangerous_goods_cert_image TYPE json USING CASE WHEN dangerous_goods_cert_image IS NULL THEN NULL WHEN dangerous_goods_cert_image = '' THEN '[]'::json ELSE dangerous_goods_cert_image::json END");

            return;
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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN license_image TYPE varchar(255) USING license_image::text');
            DB::statement('ALTER TABLE users ALTER COLUMN aviation_security_cert_image TYPE varchar(255) USING aviation_security_cert_image::text');
            DB::statement('ALTER TABLE users ALTER COLUMN dangerous_goods_cert_image TYPE varchar(255) USING dangerous_goods_cert_image::text');

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('license_image')->nullable()->change();
            $table->string('aviation_security_cert_image')->nullable()->change();
            $table->string('dangerous_goods_cert_image')->nullable()->change();
        });
    }
};
