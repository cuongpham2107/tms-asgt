<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('trip_id')
                ->nullable()
                ->constrained('trips')
                ->nullOnDelete()
                ->after('shift_id');

            $table->string('vehicle_plate_number')
                ->nullable()
                ->after('vehicle_id')
                ->comment('Biển số xe (snapshot tại thời điểm gán)');

            $table->string('vehicle_type')
                ->nullable()
                ->after('vehicle_plate_number')
                ->comment('Loại xe (snapshot tại thời điểm gán)');

            $table->index(['vehicle_id', 'status', 'trip_id']);
        });

        if (Schema::hasColumn('orders', 'sender_name')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn([
                    'sender_name',
                    'sender_contact',
                    'sender_phone',
                    'receiver_name',
                    'receiver_contact',
                    'receiver_phone',
                    'data_cargo_units',
                    'data_cargo_weight',
                    'freight_rate',
                    'surcharges',
                    'total_cost',
                ]);
            });
        }

        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->foreignId('trip_id')
                ->nullable()
                ->constrained('trips')
                ->nullOnDelete()
                ->after('order_id');

            $table->index('trip_id');
        });
    }

    public function down(): void
    {
        Schema::table('trip_checkpoints', function (Blueprint $table) {
            $table->dropIndex(['trip_id']);
            $table->dropForeign(['trip_id']);
            $table->dropColumn('trip_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['vehicle_id', 'status', 'trip_id']);
            $table->dropForeign(['trip_id']);
            $table->dropColumn('trip_id');
            $table->dropColumn('vehicle_plate_number');
            $table->dropColumn('vehicle_type');
        });
    }
};
