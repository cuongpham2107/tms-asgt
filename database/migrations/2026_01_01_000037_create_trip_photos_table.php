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
        Schema::create('trip_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_checkpoint_id')
                ->constrained('trip_checkpoints')
                ->cascadeOnDelete();
            $table->string('photo_path')->comment('Đường dẫn file ảnh trong storage');
            $table->string('photo_url')->nullable()->comment('URL công khai (nếu dùng cloud)');
            $table->timestamp('created_at')->useCurrent();

            $table->index('trip_checkpoint_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_photos');
    }
};
