<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Re-add the redundant start/end km and GPS columns to driver_shifts
        Schema::table('driver_shifts', function (Blueprint $table) {
            $table->decimal('start_km', 10, 1)->nullable()->comment('Km bắt đầu ca');
            $table->decimal('end_km', 10, 1)->nullable()->comment('Km kết thúc ca');
            $table->decimal('start_gps_lat', 10, 7)->nullable();
            $table->decimal('start_gps_lng', 10, 7)->nullable();
            $table->decimal('end_gps_lat', 10, 7)->nullable();
            $table->decimal('end_gps_lng', 10, 7)->nullable();
        });

        // Step 1: Copy start/end km and GPS from first/last shift_vehicles into driver_shifts
        $shifts = DB::table('driver_shifts')->get();

        foreach ($shifts as $shift) {
            $firstSegment = DB::table('shift_vehicles')
                ->where('shift_id', $shift->id)
                ->orderBy('start_time')
                ->first();

            $lastSegment = DB::table('shift_vehicles')
                ->where('shift_id', $shift->id)
                ->whereNotNull('end_time')
                ->orderByDesc('end_time')
                ->first();

            // Fallback: if no closed segment, use the latest segment
            if ($lastSegment === null) {
                $lastSegment = DB::table('shift_vehicles')
                    ->where('shift_id', $shift->id)
                    ->orderByDesc('start_time')
                    ->first();
            }

            if ($firstSegment !== null) {
                $updateData = [
                    'start_km' => $firstSegment->start_km,
                    'start_gps_lat' => $firstSegment->start_gps_lat,
                    'start_gps_lng' => $firstSegment->start_gps_lng,
                ];

                if ($lastSegment !== null) {
                    $updateData['end_km'] = $lastSegment->end_km;
                    $updateData['end_gps_lat'] = $lastSegment->end_gps_lat;
                    $updateData['end_gps_lng'] = $lastSegment->end_gps_lng;
                }

                DB::table('driver_shifts')
                    ->where('id', $shift->id)
                    ->update($updateData);
            }
        }

        // Step 2: Merge consecutive segments with the same vehicle into single records
        foreach ($shifts as $shift) {
            $segments = DB::table('shift_vehicles')
                ->where('shift_id', $shift->id)
                ->orderBy('start_time')
                ->get();

            if ($segments->count() <= 1) {
                continue;
            }

            // Group consecutive segments by vehicle_id
            $groups = [];
            $currentGroup = null;

            foreach ($segments as $segment) {
                if ($currentGroup === null || (int) $currentGroup['vehicle_id'] !== (int) $segment->vehicle_id) {
                    if ($currentGroup !== null) {
                        $groups[] = $currentGroup;
                    }
                    $currentGroup = [
                        'vehicle_id' => $segment->vehicle_id,
                        'keep_id' => $segment->id,
                        'start_time' => $segment->start_time,
                        'start_km' => $segment->start_km,
                        'start_gps_lat' => $segment->start_gps_lat,
                        'start_gps_lng' => $segment->start_gps_lng,
                        'end_time' => $segment->end_time,
                        'end_km' => $segment->end_km,
                        'end_gps_lat' => $segment->end_gps_lat,
                        'end_gps_lng' => $segment->end_gps_lng,
                        'delete_ids' => [],
                    ];
                } else {
                    // Same vehicle, merge into current group
                    $currentGroup['end_time'] = $segment->end_time;
                    $currentGroup['end_km'] = $segment->end_km ?? $currentGroup['end_km'];
                    $currentGroup['end_gps_lat'] = $segment->end_gps_lat ?? $currentGroup['end_gps_lat'];
                    $currentGroup['end_gps_lng'] = $segment->end_gps_lng ?? $currentGroup['end_gps_lng'];
                    $currentGroup['delete_ids'][] = $segment->id;
                }
            }

            if ($currentGroup !== null) {
                $groups[] = $currentGroup;
            }

            // Apply merges
            foreach ($groups as $group) {
                // Update the kept record with merged end data
                DB::table('shift_vehicles')
                    ->where('id', $group['keep_id'])
                    ->update([
                        'end_time' => $group['end_time'],
                        'end_km' => $group['end_km'],
                        'end_gps_lat' => $group['end_gps_lat'],
                        'end_gps_lng' => $group['end_gps_lng'],
                    ]);

                // Delete merged duplicates
                if (! empty($group['delete_ids'])) {
                    DB::table('shift_vehicles')
                        ->whereIn('id', $group['delete_ids'])
                        ->delete();
                }
            }
        }

        // Step 3: Drop the order_id column and its index
        Schema::table('shift_vehicles', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropIndex(['order_id']);
            $table->dropColumn('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('shift_vehicles', function (Blueprint $table) {
            $table->foreignId('order_id')
                ->nullable()
                ->after('vehicle_id')
                ->constrained('orders')
                ->nullOnDelete();
            $table->index('order_id');
        });
    }
};
