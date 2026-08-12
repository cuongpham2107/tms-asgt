<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

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
                    checkpoint_type TEXT NOT NULL CHECK(checkpoint_type IN ('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap','end','cancelled')),
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
            DB::statement("ALTER TABLE trip_checkpoints MODIFY COLUMN checkpoint_type ENUM('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap','end','cancelled') NOT NULL COMMENT 'Loại mốc'");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            // Remove rows with checkpoint_type = 'cancelled' since they won't fit the old enum
            DB::statement("
                CREATE TABLE trip_checkpoints_old (
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

            DB::statement("INSERT INTO trip_checkpoints_old SELECT id, trip_id, order_id, delivery_point_id, driver_id, shift_id, vehicle_id, checkpoint_type, occurred_at, km_reading, gps_lat, gps_lng, voice_note, created_at FROM trip_checkpoints WHERE checkpoint_type != 'cancelled'");

            DB::statement('DROP TABLE trip_checkpoints');
            DB::statement('ALTER TABLE trip_checkpoints_old RENAME TO trip_checkpoints');

            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_trip_id_checkpoint_type_index ON trip_checkpoints(trip_id, checkpoint_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_order_id_checkpoint_type_index ON trip_checkpoints(order_id, checkpoint_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_occurred_at_index ON trip_checkpoints(occurred_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_vehicle_id_index ON trip_checkpoints(vehicle_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS trip_checkpoints_shift_id_index ON trip_checkpoints(shift_id)');

            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            DB::statement("ALTER TABLE trip_checkpoints MODIFY COLUMN checkpoint_type ENUM('started','arrived_pickup','left_pickup','arrived_delivery','completed','driver_swap','end') NOT NULL COMMENT 'Loại mốc'");
        }
    }
};
