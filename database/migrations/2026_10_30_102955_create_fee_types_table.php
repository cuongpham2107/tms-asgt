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
        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $feeTypes = [
            'shift_storage' => 'Chi phí lưu ca',
            'warehouse_ticket' => 'Chi phí vé vào kho',
            'extra_drop' => 'Chi phí rớt điểm',
            'inspection' => 'Chi phí kiểm hóa',
            'dunnage' => 'Chi phí thuê chèn',
            'dgr_permit' => 'Chi phí giấy phép vận chuyển hàng nguy hiểm',
            'loading_unloading' => 'Chi phí bốc xếp',
            'hauling' => 'Chi phí kéo hàng',
            'forklift' => 'Chi phí xe nâng',
            'customs_supervision' => 'Chi phí giám sát hải quan',
            'sevt_lifting' => 'Chi phí nâng hạ tại SEVT',
            'haiphong_unloading' => 'Chi phí hạ hàng tại Hải Phòng',
            'other' => 'Chi phí khác',
        ];

        DB::table('fee_types')->insert(collect($feeTypes)->keys()->map(fn (string $code, int $index): array => [
            'code' => $code,
            'name' => $feeTypes[$code],
            'sort' => $index + 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_types');
    }
};
