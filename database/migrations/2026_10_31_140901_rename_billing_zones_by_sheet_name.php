<?php

use App\Models\BillingZone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Khu vực đã import trước đó lưu mã = "KCN YÊN BÌNH", tên = "Kcn Yên Bình".
 * Đổi sang: tên = cột "Địa điểm viết tắt" sheet 5 ("KCN YÊN BÌNH"), mã tự tạo từ tên ("KCN-YEN-BINH").
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $zones = DB::table('billing_zones')->orderBy('id')->get(['id', 'name']);

        // Đổi mã tạm trước để mã mới không vướng unique với mã cũ của khu vực khác.
        DB::table('billing_zones')->update(['code' => DB::raw("'tmp-' || id")]);

        $usedCodes = [];
        foreach ($zones as $zone) {
            $name = BillingZone::normalizeName($zone->name) ?? "KHU VỰC {$zone->id}";
            $base = Str::upper(Str::slug($name)) ?: 'KV';
            $code = $base;
            for ($suffix = 2; isset($usedCodes[$code]); $suffix++) {
                $code = "{$base}-{$suffix}";
            }
            $usedCodes[$code] = true;

            DB::table('billing_zones')->where('id', $zone->id)->update(['name' => $name, 'code' => $code]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('billing_zones')->update(['code' => DB::raw('name')]);
    }
};
