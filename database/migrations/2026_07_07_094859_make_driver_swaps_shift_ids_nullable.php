<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Make from_shift_id nullable in driver_swaps
        DB::statement('ALTER TABLE driver_swaps RENAME TO driver_swaps_old');

        DB::statement('CREATE TABLE driver_swaps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
            from_driver_id INTEGER NOT NULL REFERENCES users(id),
            to_driver_id INTEGER NOT NULL REFERENCES users(id),
            from_shift_id INTEGER REFERENCES driver_shifts(id) ON DELETE SET NULL,
            to_shift_id INTEGER REFERENCES driver_shifts(id) ON DELETE SET NULL,
            handover_km NUMERIC,
            reason VARCHAR NOT NULL,
            note TEXT,
            created_by INTEGER NOT NULL REFERENCES users(id),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL
        )');

        DB::statement('INSERT INTO driver_swaps SELECT * FROM driver_swaps_old');
        DB::statement('DROP TABLE driver_swaps_old');

        DB::statement('CREATE INDEX driver_swaps_trip_id_index ON driver_swaps (trip_id)');
        DB::statement('CREATE INDEX driver_swaps_from_driver_id_index ON driver_swaps (from_driver_id)');
        DB::statement('CREATE INDEX driver_swaps_to_driver_id_index ON driver_swaps (to_driver_id)');

        // 2. Add start_location_id and end_location_id to trips (for return trips)
        DB::statement('ALTER TABLE trips ADD COLUMN start_location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE trips ADD COLUMN end_location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE driver_swaps RENAME TO driver_swaps_new');

        DB::statement('CREATE TABLE driver_swaps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
            from_driver_id INTEGER NOT NULL REFERENCES users(id),
            to_driver_id INTEGER NOT NULL REFERENCES users(id),
            from_shift_id INTEGER NOT NULL REFERENCES driver_shifts(id),
            to_shift_id INTEGER REFERENCES driver_shifts(id) ON DELETE SET NULL,
            handover_km NUMERIC,
            reason VARCHAR NOT NULL,
            note TEXT,
            created_by INTEGER NOT NULL REFERENCES users(id),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL
        )');

        DB::statement('INSERT INTO driver_swaps SELECT * FROM driver_swaps_new');
        DB::statement('DROP TABLE driver_swaps_new');

        DB::statement('CREATE INDEX driver_swaps_trip_id_index ON driver_swaps (trip_id)');
        DB::statement('CREATE INDEX driver_swaps_from_driver_id_index ON driver_swaps (from_driver_id)');
        DB::statement('CREATE INDEX driver_swaps_to_driver_id_index ON driver_swaps (to_driver_id)');

        // Drop the location columns (SQLite doesn't support DROP COLUMN easily, but Laravel handles it)
    }
};
