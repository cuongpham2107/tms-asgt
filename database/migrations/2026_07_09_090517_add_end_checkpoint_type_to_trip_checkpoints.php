<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add vehicle_id column
        Schema::table('trip_checkpoints', function ($table) {
            if (! Schema::hasColumn('trip_checkpoints', 'vehicle_id')) {
                $table->foreignId('vehicle_id')
                    ->nullable()
                    ->after('shift_id')
                    ->constrained('vehicles')
                    ->nullOnDelete()
                    ->comment('Xe được sử dụng tại checkpoint (bắt buộc với type=end)');
            }
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE trip_checkpoints ALTER COLUMN trip_id DROP NOT NULL');
            DB::statement('ALTER TABLE trip_checkpoints DROP CONSTRAINT IF EXISTS trip_checkpoints_checkpoint_type_check');
            DB::statement("ALTER TABLE trip_checkpoints ADD CONSTRAINT trip_checkpoints_checkpoint_type_check CHECK (checkpoint_type IN ('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap','end'))");

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement("
                CREATE TABLE trip_checkpoints_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    trip_id INTEGER REFERENCES trips(id) ON DELETE SET NULL,
                    order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL,
                    delivery_point_id INTEGER REFERENCES order_delivery_points(id) ON DELETE SET NULL,
                    driver_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    shift_id INTEGER REFERENCES driver_shifts(id) ON DELETE SET NULL,
                    vehicle_id INTEGER REFERENCES vehicles(id) ON DELETE SET NULL,
                    checkpoint_type TEXT NOT NULL CHECK(checkpoint_type IN ('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap','end')),
                    occurred_at DATETIME,
                    km_reading NUMERIC(10,1),
                    gps_lat NUMERIC(10,7),
                    gps_lng NUMERIC(10,7),
                    voice_note TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");

            DB::statement('INSERT INTO trip_checkpoints_new SELECT id, trip_id, order_id, delivery_point_id, driver_id, shift_id, vehicle_id, checkpoint_type, occurred_at, km_reading, gps_lat, gps_lng, voice_note, created_at FROM trip_checkpoints');

            DB::statement('DROP TABLE trip_checkpoints');
            DB::statement('ALTER TABLE trip_checkpoints_new RENAME TO trip_checkpoints');

            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_trip_id_checkpoint_type_index ON trip_checkpoints(trip_id, checkpoint_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_order_id_checkpoint_type_index ON trip_checkpoints(order_id, checkpoint_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_occurred_at_index ON trip_checkpoints(occurred_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_vehicle_id_index ON trip_checkpoints(vehicle_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_shift_id_index ON trip_checkpoints(shift_id)');

            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            DB::statement("ALTER TABLE trip_checkpoints MODIFY COLUMN checkpoint_type ENUM('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap','end') NOT NULL COMMENT 'Loại mốc'");
            DB::statement('ALTER TABLE trip_checkpoints MODIFY COLUMN trip_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE trip_checkpoints DROP CONSTRAINT IF EXISTS trip_checkpoints_checkpoint_type_check');
            DB::statement("ALTER TABLE trip_checkpoints ADD CONSTRAINT trip_checkpoints_checkpoint_type_check CHECK (checkpoint_type IN ('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap'))");
            DB::statement('ALTER TABLE trip_checkpoints ALTER COLUMN trip_id SET NOT NULL');

            Schema::table('trip_checkpoints', function ($table) {
                if (Schema::hasColumn('trip_checkpoints', 'vehicle_id')) {
                    $table->dropForeign(['vehicle_id']);
                    $table->dropColumn('vehicle_id');
                }
            });

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            DB::statement("
                CREATE TABLE trip_checkpoints_old (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
                    order_id INTEGER REFERENCES orders(id) ON DELETE SET NULL,
                    delivery_point_id INTEGER REFERENCES order_delivery_points(id) ON DELETE SET NULL,
                    driver_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    shift_id INTEGER REFERENCES driver_shifts(id) ON DELETE SET NULL,
                    checkpoint_type TEXT NOT NULL CHECK(checkpoint_type IN ('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap')),
                    occurred_at DATETIME,
                    km_reading NUMERIC(10,1),
                    gps_lat NUMERIC(10,7),
                    gps_lng NUMERIC(10,7),
                    voice_note TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");

            DB::statement("INSERT INTO trip_checkpoints_old SELECT id, trip_id, order_id, delivery_point_id, driver_id, shift_id, checkpoint_type, occurred_at, km_reading, gps_lat, gps_lng, voice_note, created_at FROM trip_checkpoints WHERE checkpoint_type != 'end'");

            DB::statement('DROP TABLE trip_checkpoints');
            DB::statement('ALTER TABLE trip_checkpoints_old RENAME TO trip_checkpoints');

            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_trip_id_checkpoint_type_index ON trip_checkpoints(trip_id, checkpoint_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_order_id_checkpoint_type_index ON trip_checkpoints(order_id, checkpoint_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_occurred_at_index ON trip_checkpoints(occurred_at)');

            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            DB::statement("ALTER TABLE trip_checkpoints MODIFY COLUMN checkpoint_type ENUM('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap') NOT NULL COMMENT 'Loại mốc'");
            DB::statement('ALTER TABLE trip_checkpoints MODIFY COLUMN trip_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('trip_checkpoints', function ($table) {
            if (Schema::hasColumn('trip_checkpoints', 'vehicle_id')) {
                $table->dropForeign(['vehicle_id']);
                $table->dropColumn('vehicle_id');
            }
        });
    }
};
