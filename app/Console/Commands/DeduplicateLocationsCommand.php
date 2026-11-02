<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeduplicateLocationsCommand extends Command
{
    protected $signature = 'locations:deduplicate {--dry-run : Chỉ kiểm tra và in bảng đối chiếu, không sửa dữ liệu}';

    protected $description = 'Gộp các địa điểm (locations) bị trùng mã (code) về một ID gốc và chuyển toàn bộ khoá ngoại liên quan';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $locations = DB::table('locations')->orderBy('id')->get();

        $groups = $locations->groupBy(fn ($loc): string => mb_strtoupper(trim((string) $loc->code)));
        $duplicateGroups = $groups->filter(fn ($items): bool => $items->count() > 1);

        if ($duplicateGroups->isEmpty()) {
            $this->info('Không có mã địa điểm nào bị trùng.');

            return self::SUCCESS;
        }

        $rows = [];
        $mergePlan = [];

        foreach ($duplicateGroups as $normalizedCode => $items) {
            $sorted = $items->sort(function ($a, $b): int {
                $activeCmp = ((int) $b->is_active) <=> ((int) $a->is_active);
                if ($activeCmp !== 0) {
                    return $activeCmp;
                }

                $coordA = ($a->lat !== null && $a->lng !== null) ? 1 : 0;
                $coordB = ($b->lat !== null && $b->lng !== null) ? 1 : 0;
                if ($coordB !== $coordA) {
                    return $coordB <=> $coordA;
                }

                return ((int) $a->id) <=> ((int) $b->id);
            })->values();

            $canonical = $sorted->first();
            $duplicates = $sorted->slice(1)->values();
            $dupIds = $duplicates->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $orderCount = DB::table('orders')->whereIn('pickup_location_id', $dupIds)->count();
            $dpCount = DB::table('order_delivery_points')->whereIn('location_id', $dupIds)->count();
            $tripCount = DB::table('trips')
                ->where(fn ($q) => $q->whereIn('start_location_id', $dupIds)->orWhereIn('end_location_id', $dupIds))
                ->count();

            $rows[] = [
                $normalizedCode,
                (string) $canonical->id,
                implode(', ', $dupIds),
                (string) $orderCount,
                (string) $dpCount,
                (string) $tripCount,
            ];

            $mergePlan[] = [
                'code' => $normalizedCode,
                'canonical' => $canonical,
                'duplicates' => $duplicates,
                'dup_ids' => $dupIds,
            ];
        }

        $this->table(
            ['Mã kho', 'ID Gốc (Giữ)', 'ID Trùng (Xoá)', 'Đơn lấy hàng', 'Điểm giao hàng', 'Chuyến đi'],
            $rows,
        );

        if ($dryRun) {
            $this->warn('Chế độ --dry-run: Chưa thực hiện thay đổi nào trên cơ sở dữ liệu.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($mergePlan): void {
            $hasTripStart = Schema::hasColumn('trips', 'start_location_id');
            $hasTripEnd = Schema::hasColumn('trips', 'end_location_id');
            $hasCustomerLoc = Schema::hasColumn('customers', 'location_id');
            $hasRateOrigin = Schema::hasTable('freight_rates') && Schema::hasColumn('freight_rates', 'origin_location_id');
            $hasRateDest = Schema::hasTable('freight_rates') && Schema::hasColumn('freight_rates', 'destination_location_id');

            foreach ($mergePlan as $item) {
                $canonical = $item['canonical'];
                $canonicalId = (int) $canonical->id;
                $dupIds = $item['dup_ids'];

                $updates = ['code' => $item['code']];
                foreach ($item['duplicates'] as $dup) {
                    if (($canonical->lat === null || $canonical->lng === null) && $dup->lat !== null && $dup->lng !== null) {
                        $updates['lat'] = $dup->lat;
                        $updates['lng'] = $dup->lng;
                    }
                    if (empty($canonical->billing_zone_id) && ! empty($dup->billing_zone_id)) {
                        $updates['billing_zone_id'] = $dup->billing_zone_id;
                    }
                    if (empty($canonical->geofence_radius_m) && ! empty($dup->geofence_radius_m)) {
                        $updates['geofence_radius_m'] = $dup->geofence_radius_m;
                    }
                }

                DB::table('orders')->whereIn('pickup_location_id', $dupIds)->update(['pickup_location_id' => $canonicalId]);
                DB::table('order_delivery_points')->whereIn('location_id', $dupIds)->update(['location_id' => $canonicalId]);

                if ($hasCustomerLoc) {
                    DB::table('customers')->whereIn('location_id', $dupIds)->update(['location_id' => $canonicalId]);
                }
                if ($hasTripStart) {
                    DB::table('trips')->whereIn('start_location_id', $dupIds)->update(['start_location_id' => $canonicalId]);
                }
                if ($hasTripEnd) {
                    DB::table('trips')->whereIn('end_location_id', $dupIds)->update(['end_location_id' => $canonicalId]);
                }
                if ($hasRateOrigin) {
                    DB::table('freight_rates')->whereIn('origin_location_id', $dupIds)->update(['origin_location_id' => $canonicalId]);
                }
                if ($hasRateDest) {
                    DB::table('freight_rates')->whereIn('destination_location_id', $dupIds)->update(['destination_location_id' => $canonicalId]);
                }

                if (Schema::hasTable('customer_location')) {
                    $pivots = DB::table('customer_location')->whereIn('location_id', $dupIds)->get();
                    foreach ($pivots as $pivot) {
                        $exists = DB::table('customer_location')
                            ->where('customer_id', $pivot->customer_id)
                            ->where('location_id', $canonicalId)
                            ->exists();

                        if (! $exists) {
                            DB::table('customer_location')->where('id', $pivot->id)->update(['location_id' => $canonicalId]);
                        } else {
                            DB::table('customer_location')->where('id', $pivot->id)->delete();
                        }
                    }
                }

                DB::table('locations')->whereIn('id', $dupIds)->delete();
                DB::table('locations')->where('id', $canonicalId)->update($updates);
            }
        });

        Cache::forget('location-options-all');
        Cache::forget('active-locations-with-coords-v2');

        $this->info('Đã gộp thành công '.count($mergePlan).' nhóm mã kho trùng.');

        return self::SUCCESS;
    }
}
