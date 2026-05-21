<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds trip checkpoints for timeline testing.
 *
 * Fixes missing data for Trips table columns:
 *  - ORD-2026-0412-001 (sent)      : 4 checkpoints with Hoang + shift
 *  - ORD-2026-0412-006 (started)   : 8 checkpoints, Phuc→An swap, updates order driver
 *  - ORD-2026-0411-001 (completed) : 5 checkpoints (3 new)
 *
 * Run: php artisan db:seed --class=TripTimelineDemoSeeder
 */
class TripTimelineDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // ── Resolve FK IDs ───────────────────────────────────────────
        $orderIds = DB::table('orders')
            ->whereIn('order_code', ['ORD-2026-0412-001', 'ORD-2026-0412-006', 'ORD-2026-0411-001'])
            ->pluck('id', 'order_code');

        $userIds = DB::table('users')
            ->whereIn('email', [
                'driver.hoang@example.com',
                'driver.phuc@example.com',
                'driver.an@example.com',
            ])
            ->pluck('id', 'email');

        $vehicleIds = DB::table('vehicles')
            ->whereIn('plate_number', ['20C-08678', '51D-23456'])
            ->pluck('id', 'plate_number');

        $locationIds = DB::table('locations')
            ->whereIn('code', ['BN', 'TN', 'SEMV', 'ALSC'])
            ->pluck('id', 'code');

        // ── Ensure shifts exist ──────────────────────────────────────
        // Hoang on 20C-08678
        DB::table('driver_shifts')->updateOrInsert(
            ['driver_id' => $userIds['driver.hoang@example.com'], 'start_time' => $now->copy()->setTime(6, 0, 0)],
            [
                'vehicle_id' => $vehicleIds['20C-08678'],
                'shift_type' => 'full',
                'start_km' => 12350.0,
                'start_gps_lat' => 21.2142000,
                'start_gps_lng' => 105.8027000,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // Phuc on 51D-23456 (started yesterday)
        DB::table('driver_shifts')->updateOrInsert(
            ['driver_id' => $userIds['driver.phuc@example.com'], 'start_time' => $now->copy()->subDay()->setTime(17, 0, 0)],
            [
                'vehicle_id' => $vehicleIds['51D-23456'],
                'shift_type' => 'night_half',
                'start_km' => 45021.0,
                'start_gps_lat' => 21.0285000,
                'start_gps_lng' => 105.8542000,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // An on 51D-23456 (started this morning)
        DB::table('driver_shifts')->updateOrInsert(
            ['driver_id' => $userIds['driver.an@example.com'], 'start_time' => $now->copy()->setTime(6, 0, 0)],
            [
                'vehicle_id' => $vehicleIds['51D-23456'],
                'shift_type' => 'full',
                'start_km' => 8200.0,
                'start_gps_lat' => 21.1861000,
                'start_gps_lng' => 106.0763000,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $shiftHoang = DB::table('driver_shifts')
            ->where('driver_id', $userIds['driver.hoang@example.com'])
            ->latest('start_time')->value('id');
        $shiftPhuc = DB::table('driver_shifts')
            ->where('driver_id', $userIds['driver.phuc@example.com'])
            ->latest('start_time')->value('id');
        $shiftAn = DB::table('driver_shifts')
            ->where('driver_id', $userIds['driver.an@example.com'])
            ->latest('start_time')->value('id');

        // ── Ensure delivery points ───────────────────────────────────
        // ORD-2026-0412-001: SEMV → ALSC
        DB::table('order_delivery_points')->updateOrInsert(
            ['order_id' => $orderIds['ORD-2026-0412-001'], 'sequence' => 1],
            [
                'location_id' => $locationIds['ALSC'],
                'address' => 'ALSC - Ga hàng hóa Nội Bài',
                'contact_person' => 'ALSC cargo',
                'contact_phone' => '0902000001',
                'status' => 'pending',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $dp001 = DB::table('order_delivery_points')
            ->where('order_id', $orderIds['ORD-2026-0412-001'])
            ->where('sequence', 1)->value('id');

        // ORD-2026-0412-006: BN + TN
        DB::table('order_delivery_points')->updateOrInsert(
            ['order_id' => $orderIds['ORD-2026-0412-006'], 'sequence' => 1],
            [
                'location_id' => $locationIds['BN'],
                'address' => 'KCN Tiên Sơn, Bắc Ninh',
                'contact_person' => 'Kho BN',
                'contact_phone' => '0902000006',
                'status' => 'pending',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
        DB::table('order_delivery_points')->updateOrInsert(
            ['order_id' => $orderIds['ORD-2026-0412-006'], 'sequence' => 2],
            [
                'location_id' => $locationIds['TN'],
                'address' => 'KCN Yên Bình, Thái Nguyên',
                'contact_person' => 'Kho TN',
                'contact_phone' => '0902000011',
                'status' => 'pending',
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $dpIds006 = DB::table('order_delivery_points')
            ->where('order_id', $orderIds['ORD-2026-0412-006'])
            ->orderBy('sequence')->pluck('id');

        // ORD-2026-0411-001 delivery point
        $dp001Old = DB::table('order_delivery_points')
            ->where('order_id', $orderIds['ORD-2026-0411-001'])
            ->where('sequence', 1)->value('id');

        // ── ORD-2026-0412-006: fix order driver → An ─────────────────
        DB::table('orders')
            ->where('id', $orderIds['ORD-2026-0412-006'])
            ->update(['driver_id' => $userIds['driver.an@example.com'], 'updated_at' => $now]);

        // ── Checkpoints ──────────────────────────────────────────────

        // ORD-2026-0412-001 (sent): 4 checkpoints — Hoàng chở SEMV→ALSC
        $this->seedCheckpoints($orderIds['ORD-2026-0412-001'], $userIds['driver.hoang@example.com'], $shiftHoang, [
            ['started',          $now->copy()->setTime(14, 5, 0), $dp001, 12351.0, 21.1861, 106.0763, 'Xuất phát từ bãi xe SEMV'],
            ['arrived_pickup',   $now->copy()->setTime(14, 30, 0), $dp001, 12355.0, 21.1980, 106.0520, 'Đến SEMV lấy dây đai'],
            ['left_pickup',      $now->copy()->setTime(15, 0, 0), $dp001, 12355.0, 21.2100, 106.0100, 'Đã load xong, rời SEMV'],
            ['arrived_delivery', $now->copy()->setTime(15, 45, 0), $dp001, 12370.0, 21.2142, 105.8027, 'Đến ALSC, chờ bốc xếp'],
        ]);

        // ORD-2026-0412-006 (started): 8 checkpoints — Phúc→An, BN→BD
        $this->seedCheckpoints($orderIds['ORD-2026-0412-006'], $userIds['driver.an@example.com'], $shiftAn, [
            ['started',          $now->copy()->setTime(8, 5, 0), $dpIds006[0], 8201.2, 21.0285, 105.8542, 'Xuất phát từ bãi, kiểm tra xe OK'],
            ['arrived_pickup',   $now->copy()->setTime(8, 45, 0), $dpIds006[0], 8220.5, 21.1167, 105.9583, 'Đến kho BN lấy container seal tạm'],
            ['left_pickup',      $now->copy()->setTime(9, 20, 0), $dpIds006[0], 8220.5, 21.1861, 106.0763, 'Đã load hàng xong, rời kho BN'],
            ['driver_swap',      $now->copy()->setTime(10, 0, 0), $dpIds006[0], 8245.0, 21.3019, 105.8995, 'Đảo lái tại Sóc Sơn — bàn giao cho An'],
            ['arrived_delivery', $now->copy()->setTime(11, 30, 0), $dpIds006[1], 8298.3, 21.4200, 105.8900, 'Đến kho Phổ Yên, chờ bốc xếp'],
            ['arrived_delivery', $now->copy()->setTime(12, 45, 0), $dpIds006[1], 8320.1, 21.5150, 105.8750, 'Điểm giao thứ 2 — Thái Nguyên'],
            ['arrived_delivery', $now->copy()->setTime(14, 15, 0), $dpIds006[1], 8355.7, 21.5942, 105.8482, 'Điểm giao cuối — KCN Yên Bình'],
            ['completed',        $now->copy()->setTime(15, 0, 0), $dpIds006[1], 8360.0, 21.5960, 105.8500, 'Đã giao hết hàng, khách ký nhận đủ'],
        ]);

        // ORD-2026-0411-001 (completed): 5 checkpoints total
        $this->seedCheckpoints($orderIds['ORD-2026-0411-001'], $userIds['driver.phuc@example.com'], $shiftPhuc, [
            ['started',          $now->copy()->subDay()->setTime(20, 0, 0), $dp001Old, 45080.0, 21.0285, 105.8542, 'Bắt đầu chuyến giao hàng đông lạnh'],
            ['arrived_pickup',   $now->copy()->subDay()->setTime(20, 30, 0), $dp001Old, 45095.0, 21.1167, 105.9583, 'Đến điểm lấy hàng đông lạnh'],
            ['left_pickup',      $now->copy()->subDay()->setTime(21, 0, 0), $dp001Old, 45095.0, 21.1861, 106.0763, 'Đã load xong, kiểm tra nhiệt độ 2°C'],
            ['arrived_delivery', $now->copy()->subDay()->setTime(22, 30, 0), $dp001Old, 45140.0, 21.3019, 105.8995, 'Đến kho Phổ Yên, chờ dỡ hàng'],
            ['completed',        $now->copy()->subDay()->setTime(23, 0, 0), $dp001Old, 45160.0, 21.5942, 105.8482, 'Đã giao hàng xong, ký nhận đầy đủ'],
        ]);

        // ── Summary ──────────────────────────────────────────────────
        foreach (['ORD-2026-0412-001', 'ORD-2026-0412-006', 'ORD-2026-0411-001'] as $code) {
            $cnt = DB::table('trip_checkpoints')->where('order_id', $orderIds[$code])->count();
            $this->command->info("  {$code}: {$cnt} checkpoints");
        }
    }

    /**
     * @param  array<int, array{0: string, 1: Carbon, 2: int, 3: float, 4: float, 5: float, 6: string}>  $rows
     */
    private function seedCheckpoints(int $orderId, int $driverId, int $shiftId, array $rows): void
    {
        $now = now();

        foreach ($rows as $row) {
            DB::table('trip_checkpoints')->updateOrInsert(
                [
                    'order_id' => $orderId,
                    'checkpoint_type' => $row[0],
                    'occurred_at' => $row[1],
                ],
                [
                    'driver_id' => $driverId,
                    'shift_id' => $shiftId,
                    'delivery_point_id' => $row[2],
                    'km_reading' => $row[3],
                    'gps_lat' => $row[4],
                    'gps_lng' => $row[5],
                    'voice_note' => $row[6],
                    'created_at' => $now,
                ]
            );
        }
    }
}
