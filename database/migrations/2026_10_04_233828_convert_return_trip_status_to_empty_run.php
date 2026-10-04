<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Trạng thái return_trip được thay bằng cờ is_empty_run + trạng thái thường.
     * Chuyến không hàng đang ở return_trip nghĩa là lái xe chưa kết thúc → started.
     */
    public function up(): void
    {
        DB::table('trips')
            ->where('status', 'return_trip')
            ->update(['status' => 'started', 'is_empty_run' => true]);
    }

    public function down(): void
    {
        DB::table('trips')
            ->where('is_empty_run', true)
            ->where('status', 'started')
            ->update(['status' => 'return_trip']);
    }
};
