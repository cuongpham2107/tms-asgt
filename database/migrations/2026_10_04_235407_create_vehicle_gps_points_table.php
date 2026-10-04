<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_gps_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('driver_shifts')->nullOnDelete();
            $table->string('device_id', 64)->nullable();
            $table->unsignedBigInteger('seq')->nullable();
            $table->dateTime('recorded_at');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->float('speed')->nullable()->comment('km/h');
            $table->float('heading')->nullable();
            $table->float('accuracy')->nullable()->comment('mét');
            $table->boolean('mocked')->default(false);
            $table->string('source', 10)->comment('phone | eup');
            $table->timestamp('created_at')->nullable();

            $table->unique(['device_id', 'seq']);
            $table->index(['vehicle_id', 'recorded_at']);
            $table->index(['driver_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_gps_points');
    }
};
