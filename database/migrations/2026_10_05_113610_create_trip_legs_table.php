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
        Schema::create('trip_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('leg_index');
            $table->foreignId('from_checkpoint_id')->nullable()->constrained('trip_checkpoints')->nullOnDelete();
            $table->foreignId('to_checkpoint_id')->nullable()->constrained('trip_checkpoints')->nullOnDelete();
            $table->string('from_name');
            $table->string('to_name');
            $table->dateTime('from_time')->nullable();
            $table->dateTime('to_time')->nullable();
            $table->decimal('distance_km', 10, 1)->default(0);
            $table->decimal('distance_adjusted_km', 10, 1)->nullable();
            $table->boolean('is_loaded')->default(false);
            $table->string('source')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('adjust_reason')->nullable();
            $table->dateTime('adjusted_at')->nullable();
            $table->timestamps();

            $table->unique(['trip_id', 'leg_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trip_legs');
    }
};
