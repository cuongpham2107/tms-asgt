<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->decimal('total_weight', 8, 2)->nullable()->after('load_capacity')->comment('Tổng trọng tải (tấn)');
            $table->decimal('cargo_volume', 10, 2)->nullable()->after('total_weight')->comment('Số khối thực tế (m³)');
            $table->integer('box_length')->nullable()->after('cargo_volume')->comment('Dài (mm)');
            $table->integer('box_width')->nullable()->after('box_length')->comment('Rộng (mm)');
            $table->integer('box_height')->nullable()->after('box_width')->comment('Cao (mm)');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['box_height', 'box_width', 'box_length', 'cargo_volume', 'total_weight']);
        });
    }
};
