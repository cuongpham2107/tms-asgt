<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('driver_swaps_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete();
            $table->foreignId('from_driver_id')
                ->constrained('users');
            $table->foreignId('to_driver_id')
                ->constrained('users');
            $table->foreignId('from_shift_id')
                ->nullable()
                ->constrained('driver_shifts')
                ->nullOnDelete();
            $table->foreignId('to_shift_id')
                ->nullable()
                ->constrained('driver_shifts')
                ->nullOnDelete();
            $table->decimal('handover_km', 10, 1)->nullable();
            $table->string('reason');
            $table->text('note')->nullable();
            $table->foreignId('created_by')
                ->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('trip_id');
            $table->index('from_driver_id');
            $table->index('to_driver_id');
        });

        DB::statement('INSERT INTO driver_swaps_new SELECT * FROM driver_swaps');

        Schema::drop('driver_swaps');
        Schema::rename('driver_swaps_new', 'driver_swaps');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::create('driver_swaps_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')
                ->constrained('trips')
                ->cascadeOnDelete();
            $table->foreignId('from_driver_id')
                ->constrained('users');
            $table->foreignId('to_driver_id')
                ->constrained('users');
            $table->foreignId('from_shift_id')
                ->constrained('driver_shifts');
            $table->foreignId('to_shift_id')
                ->nullable()
                ->constrained('driver_shifts')
                ->nullOnDelete();
            $table->decimal('handover_km', 10, 1)->nullable();
            $table->string('reason');
            $table->text('note')->nullable();
            $table->foreignId('created_by')
                ->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('trip_id');
            $table->index('from_driver_id');
            $table->index('to_driver_id');
        });

        DB::statement('INSERT INTO driver_swaps_old SELECT * FROM driver_swaps');

        Schema::drop('driver_swaps');
        Schema::rename('driver_swaps_old', 'driver_swaps');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
