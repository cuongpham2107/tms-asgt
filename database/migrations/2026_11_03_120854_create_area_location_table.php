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
        Schema::create('area_location', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['area_id', 'location_id']);
        });

        // 1. Sao chép toàn bộ quan hệ area_id hiện tại từ locations sang area_location
        $now = now();
        $locations = DB::table('locations')->whereNotNull('area_id')->get(['id', 'area_id']);
        $inserts = [];
        foreach ($locations as $loc) {
            $inserts[] = [
                'area_id' => $loc->area_id,
                'location_id' => $loc->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($inserts)) {
            DB::table('area_location')->insert($inserts);
        }

        // 2. Gán thêm khu vực ASG cho toàn bộ các địa điểm đang thuộc NBA theo yêu cầu khách hàng
        $nbaAreaId = DB::table('areas')->where('code', 'NBA')->value('id');
        $asgAreaId = DB::table('areas')->where('code', 'ASG')->value('id');

        if ($nbaAreaId && $asgAreaId) {
            $nbaLocationIds = DB::table('area_location')
                ->where('area_id', $nbaAreaId)
                ->pluck('location_id');

            foreach ($nbaLocationIds as $locId) {
                $exists = DB::table('area_location')
                    ->where('area_id', $asgAreaId)
                    ->where('location_id', $locId)
                    ->exists();

                if (! $exists) {
                    DB::table('area_location')->insert([
                        'area_id' => $asgAreaId,
                        'location_id' => $locId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('area_location');
    }
};
