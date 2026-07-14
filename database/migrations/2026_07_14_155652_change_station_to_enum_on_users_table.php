<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean up old station values before adding CHECK constraint
        DB::table('users')->where('station', 'T')->update(['station' => 'TN']);
        DB::table('users')->where('station', 'BE')->update(['station' => 'BN']);

        // SQLite: recreate column with CHECK constraint
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement('ALTER TABLE users RENAME TO users_old');

            DB::statement('CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR NOT NULL,
                email VARCHAR NOT NULL UNIQUE,
                email_verified_at DATETIME,
                password VARCHAR NOT NULL,
                date_of_birth DATE,
                license_class TEXT CHECK(license_class IN ("B","B1","C1","C","FC","D","E")),
                license_number VARCHAR,
                license_expiry_date DATE,
                license_image VARCHAR,
                cccd VARCHAR(20),
                cccd_issue_date DATE,
                certificates TEXT,
                station TEXT CHECK(station IN ("TN","BN","NBA") OR station IS NULL),
                license_issue_date DATE,
                phone VARCHAR(20),
                address VARCHAR,
                avatar VARCHAR,
                is_active TINYINT(1) DEFAULT 0 NOT NULL,
                remember_token VARCHAR(100),
                created_at DATETIME,
                updated_at DATETIME
            )');

            DB::statement('INSERT INTO users SELECT * FROM users_old');
            DB::statement('DROP TABLE users_old');

            // Recreate indexes
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');

            return;
        }

        // MySQL/PostgreSQL: change column to enum
        Schema::table('users', function ($table) {
            $table->string('station')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE users RENAME TO users_old');

            DB::statement('CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR NOT NULL,
                email VARCHAR NOT NULL UNIQUE,
                email_verified_at DATETIME,
                password VARCHAR NOT NULL,
                date_of_birth DATE,
                license_class TEXT CHECK(license_class IN ("B","B1","C1","C","FC","D","E")),
                license_number VARCHAR,
                license_expiry_date DATE,
                license_image VARCHAR,
                cccd VARCHAR(20),
                cccd_issue_date DATE,
                certificates TEXT,
                station VARCHAR,
                license_issue_date DATE,
                phone VARCHAR(20),
                address VARCHAR,
                avatar VARCHAR,
                is_active TINYINT(1) DEFAULT 0 NOT NULL,
                remember_token VARCHAR(100),
                created_at DATETIME,
                updated_at DATETIME
            )');

            DB::statement('INSERT INTO users SELECT * FROM users_old');
            DB::statement('DROP TABLE users_old');

            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');

            return;
        }

        Schema::table('users', function ($table) {
            $table->string('station')->nullable()->change();
        });
    }
};
