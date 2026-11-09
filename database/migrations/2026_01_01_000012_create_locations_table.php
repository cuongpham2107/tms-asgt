<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100);
            $table->string('name');
            $table->text('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->enum('loc_type', ['pickup', 'delivery', 'warehouse', 'other'])->default('warehouse');
            $table->boolean('is_active')->default(true);
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('billing_zone_id')->nullable()->constrained('billing_zones')->nullOnDelete();
            $table->integer('geofence_radius_m')->nullable();
            $table->string('old_address')->nullable();
            $table->timestamps();

            $table->unique(['code', 'area_id']);
            $table->index('code');
            $table->index('loc_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
