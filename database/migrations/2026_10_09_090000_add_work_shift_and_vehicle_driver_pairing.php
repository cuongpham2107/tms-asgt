<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'work_shift')) {
                $table->string('work_shift', 10)->nullable()->comment('Ca trực: even = Ca chẵn, odd = Ca lẻ');
            }
            if (! Schema::hasColumn('users', 'vehicle_id')) {
                $table->unsignedBigInteger('vehicle_id')->nullable()->index()->comment('Xe phụ trách');
            }
        });

        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'even_driver_id')) {
                $table->unsignedBigInteger('even_driver_id')->nullable()->index()->comment('Lái xe ca chẵn');
            }
            if (! Schema::hasColumn('vehicles', 'odd_driver_id')) {
                $table->unsignedBigInteger('odd_driver_id')->nullable()->index()->comment('Lái xe ca lẻ');
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            });

            Schema::table('vehicles', function (Blueprint $table) {
                $table->foreign('even_driver_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('odd_driver_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropForeign(['even_driver_id']);
                $table->dropForeign(['odd_driver_id']);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['vehicle_id']);
            });
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $cols = array_filter(['even_driver_id', 'odd_driver_id'], fn ($c) => Schema::hasColumn('vehicles', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(['work_shift', 'vehicle_id'], fn ($c) => Schema::hasColumn('users', $c));
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
