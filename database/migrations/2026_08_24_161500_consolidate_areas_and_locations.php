<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Resolve canonical areas by code
        $areas = DB::table('areas')->orderBy('id', 'asc')->get();
        $canonicalAreaMap = []; // code => canonical_id
        $oldToNewAreaMap = [];  // old_area_id => canonical_area_id

        foreach ($areas as $area) {
            if (! isset($canonicalAreaMap[$area->code])) {
                $canonicalAreaMap[$area->code] = $area->id;
            }
            $oldToNewAreaMap[$area->id] = $canonicalAreaMap[$area->code];
        }

        // 2. Remap orders.area_id
        foreach ($oldToNewAreaMap as $oldId => $newId) {
            if ($oldId !== $newId) {
                DB::table('orders')->where('area_id', $oldId)->update(['area_id' => $newId]);
            }
        }

        // 3. Consolidate duplicate locations (by code)
        $locations = DB::table('locations')->orderBy('id', 'asc')->get();
        $canonicalLocationMap = []; // code => canonical_location_id
        $oldToNewLocMap = [];       // old_loc_id => canonical_loc_id
        $duplicateLocationIds = [];

        foreach ($locations as $loc) {
            if (! isset($canonicalLocationMap[$loc->code])) {
                $canonicalLocationMap[$loc->code] = $loc->id;
            } else {
                $duplicateLocationIds[] = $loc->id;
            }
            $oldToNewLocMap[$loc->id] = $canonicalLocationMap[$loc->code];
        }

        foreach ($oldToNewLocMap as $oldId => $newId) {
            if ($oldId !== $newId) {
                DB::table('orders')->where('pickup_location_id', $oldId)->update(['pickup_location_id' => $newId]);
                DB::table('order_delivery_points')->where('location_id', $oldId)->update(['location_id' => $newId]);
                DB::table('customers')->where('location_id', $oldId)->update(['location_id' => $newId]);

                // customer_location pivot
                $existingPivots = DB::table('customer_location')->where('location_id', $oldId)->get();
                foreach ($existingPivots as $pivot) {
                    $alreadyHasNew = DB::table('customer_location')
                        ->where('customer_id', $pivot->customer_id)
                        ->where('location_id', $newId)
                        ->exists();

                    if (! $alreadyHasNew) {
                        DB::table('customer_location')
                            ->where('id', $pivot->id)
                            ->update(['location_id' => $newId]);
                    } else {
                        DB::table('customer_location')->where('id', $pivot->id)->delete();
                    }
                }
            }
        }

        // 4. Delete duplicate locations
        if (! empty($duplicateLocationIds)) {
            DB::table('locations')->whereIn('id', $duplicateLocationIds)->delete();
        }

        // 5. Update area_id for remaining locations
        foreach ($oldToNewAreaMap as $oldAreaId => $newAreaId) {
            if ($oldAreaId !== $newAreaId) {
                DB::table('locations')->where('area_id', $oldAreaId)->update(['area_id' => $newAreaId]);
            }
        }

        // 6. Delete duplicate areas
        $canonicalAreaIds = array_values($canonicalAreaMap);
        if (! empty($canonicalAreaIds)) {
            DB::table('areas')->whereNotIn('id', $canonicalAreaIds)->delete();
        }

        // 7. Make areas.type nullable
        if (Schema::hasColumn('areas', 'type')) {
            Schema::table('areas', function (Blueprint $table) {
                $table->string('type')->nullable()->change();
            });
        }

        // 8. Update unique index on locations to unique('code')
        try {
            Schema::table('locations', function (Blueprint $table) {
                $table->dropUnique(['code', 'area_id']);
                $table->unique('code');
            });
        } catch (Throwable $e) {
            // Ignore in SQLite if already adjusted
        }
    }

    public function down(): void
    {
        //
    }
};
