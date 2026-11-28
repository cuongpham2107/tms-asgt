<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const ALLOWED_TYPES = [
        'normal',
        'cold',
        'anti_vibration',
        'container',
        'flatbed',
        'bat_wing',
        'aviation_security',
        'other',
    ];

    /** @var list<string> */
    private const PREVIOUS_TYPES = [
        'normal',
        'cold',
        'anti_vibration',
        'container',
        'flatbed',
        'bat_wing',
        'other',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(', ', array_map(fn (string $v): string => "'{$v}'::character varying", self::ALLOWED_TYPES));
            DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_vehicle_type_check');
            DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_vehicle_type_check CHECK ((vehicle_type::text = ANY ((ARRAY[{$values}])::text[])))");

            return;
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->enum('vehicle_type', self::ALLOWED_TYPES)->default('normal')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(', ', array_map(fn (string $v): string => "'{$v}'::character varying", self::PREVIOUS_TYPES));
            DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_vehicle_type_check');
            DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_vehicle_type_check CHECK ((vehicle_type::text = ANY ((ARRAY[{$values}])::text[])))");

            return;
        }

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->enum('vehicle_type', self::PREVIOUS_TYPES)->default('normal')->change();
        });
    }
};
