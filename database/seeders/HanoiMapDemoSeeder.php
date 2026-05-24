<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HanoiMapDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // ── Resolve FK IDs ───────────────────────────────────────────
        $userIdOps1 = DB::table('users')->where('email', 'ops1@example.com')->value('id');
        $driverHoang = DB::table('users')->where('email', 'driver.hoang@example.com')->value('id');
        $driverToan = DB::table('users')->where('email', 'driver.toan@example.com')->value('id');
        $driverPhuc = DB::table('users')->where('email', 'driver.phuc@example.com')->value('id');
        $driverAn = DB::table('users')->where('email', 'driver.an@example.com')->value('id');
        $driverBinh = DB::table('users')->where('email', 'driver.binh@example.com')->value('id');

        $vehicle1 = DB::table('vehicles')->where('plate_number', '20C-08678')->value('id');
        $vehicle2 = DB::table('vehicles')->where('plate_number', '99H-00948')->value('id');
        $vehicle3 = DB::table('vehicles')->where('plate_number', '51D-23456')->value('id');
        $vehicle5 = DB::table('vehicles')->where('plate_number', '51E-34567')->value('id');
        $vehicle6 = DB::table('vehicles')->where('plate_number', '29H-12345')->value('id');
        $vehicle7 = DB::table('vehicles')->where('plate_number', '51G-56789')->value('id');

        $customerASGL = DB::table('customers')->where('code', 'ASGL')->value('id');
        $customerBIGC = DB::table('customers')->where('code', 'BIGCGV')->value('id');
        $customerKLDNA = DB::table('customers')->where('code', 'KLDNA')->value('id');
        $customerBACHHO = DB::table('customers')->where('code', 'BACHHO')->value('id');

        $catTN = DB::table('order_categories')->where('code', 'TN')->value('id');
        $catBN = DB::table('order_categories')->where('code', 'BN')->value('id');
        $catNBA = DB::table('order_categories')->where('code', 'NBA')->value('id');

        // ── Update locations with Hanoi-area coordinates ─────────────
        $hanoiLocs = [
            ['SEMV',  'Khu Công Nghệ Cao, TP. Thủ Đức',     'pickup',    10.8554, 106.7913],
            ['ALSC',  'Ga hàng hóa Tân Sơn Nhất',           'delivery',  10.8188, 106.6580],
            ['NBA',   'Nội Bài A - Sân bay Nội Bài',        'warehouse', 21.2179, 105.8038],
            ['NBO',   'Nội Bài kho ngoài - Sóc Sơn',        'warehouse', 21.2599, 105.8302],
            ['TN',    'Tây Nam - KCN Thạch Thất, HN',        'delivery',  20.9950, 105.6400],
            ['BN',    'Bắc Nam - KCN Bắc Thăng Long, HN',    'delivery',  21.1385, 105.7652],
            ['PROV',  'Đa tuyến liên tỉnh - HN',             'other',     21.0285, 105.8542],
            ['CATLA', 'Cảng Hà Nội - Chương Dương',          'pickup',    21.0515, 105.8578],
            ['ICD',   'ICD Mỹ Đình - Nam Từ Liêm',           'warehouse', 21.0300, 105.7800],
            ['TANSO', 'Tân Sơn Nhất - HN (ga hàng không)',   'pickup',    21.2100, 105.8100],
        ];

        foreach ($hanoiLocs as $l) {
            DB::table('locations')->where('code', $l[0])->update([
                'name' => $l[1],
                'loc_type' => $l[2],
                'lat' => $l[3],
                'lng' => $l[4],
            ]);
        }

        echo 'Updated '.count($hanoiLocs)." locations.\n";

        $lid = fn ($code) => DB::table('locations')->where('code', $code)->value('id');

        // ── Helper to create orders ──────────────────────────────────
        $createdOrders = [];

        $makeOrder = function (
            string $code, int $vehicleId, ?int $driverId, string $status,
            string $catCode, int $customerId, string $pickupLocCode,
            Carbon $plannedAt, string $cargoName, int $packages, float $weight,
            ?Carbon $sentAt = null,
        ) use ($now, $userIdOps1, $lid, &$createdOrders, $catTN, $catBN, $catNBA) {
            $catId = match ($catCode) {
                'TN' => $catTN,
                'BN' => $catBN,
                'NBA' => $catNBA,
                default => $catTN,
            };
            $lid = fn ($code) => DB::table('locations')->where('code', $code)->value('id');

            DB::table('orders')->updateOrInsert(
                ['order_code' => $code],
                [
                    'type' => 'HHHK',
                    'order_type_id' => 1,
                    'order_category_id' => $catId,
                    'customer_id' => $customerId,
                    'cargo_name' => $cargoName,
                    'cargo_type' => 'GCR',
                    'total_packages' => $packages,
                    'total_weight' => $weight,
                    'pickup_location_id' => $lid($pickupLocCode),
                    'pickup_address' => 'Kho '.$pickupLocCode,
                    'pickup_contact' => 'LH Kho',
                    'pickup_phone' => '0901'.rand(100000, 999999),
                    'planned_loading_at' => $plannedAt,
                    'vehicle_id' => $vehicleId,
                    'driver_id' => $driverId,
                    'status' => $status,
                    'is_return_trip' => false,
                    'created_by' => $driverId ?? $userIdOps1,
                    'sent_at' => $sentAt,
                    'notes' => 'Hà Nội - '.$status,
                    'created_at' => $plannedAt,
                    'updated_at' => $now,
                ]
            );
            $oid = DB::table('orders')->where('order_code', $code)->value('id');
            $createdOrders[$code] = $oid;

            return $oid;
        };

        // ── Create delivery points helper ────────────────────────────
        $makeDP = function (int $orderId, int $sequence, string $locCode, string $contact, string $phone) use ($now, $lid) {
            DB::table('order_delivery_points')->updateOrInsert(
                ['order_id' => $orderId, 'sequence' => $sequence],
                [
                    'location_id' => $lid($locCode),
                    'address' => $locCode.' - '.$contact,
                    'contact_person' => $contact,
                    'contact_phone' => $phone,
                    'status' => 'pending',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            return DB::table('order_delivery_points')
                ->where('order_id', $orderId)->where('sequence', $sequence)
                ->value('id');
        };

        // ── Create trip checkpoints helper ───────────────────────────
        $makeCP = function (int $orderId, int $driverId, int $shiftId, string $type, Carbon $time, ?int $dpId, float $km, float $lat, float $lng, string $note) use ($now) {
            DB::table('trip_checkpoints')->updateOrInsert(
                ['order_id' => $orderId, 'checkpoint_type' => $type, 'occurred_at' => $time],
                [
                    'driver_id' => $driverId,
                    'shift_id' => $shiftId,
                    'delivery_point_id' => $dpId,
                    'km_reading' => $km,
                    'gps_lat' => $lat,
                    'gps_lng' => $lng,
                    'voice_note' => $note,
                    'created_at' => $now,
                ]
            );
        };

        // ── Ensure active shifts exist for each vehicle/driver ───────
        $findOrCreateShift = function (int $driverId, int $vehicleId, Carbon $startTime, float $startKm, float $gpsLat, float $gpsLng, string $shiftType = 'full') {
            DB::table('driver_shifts')->updateOrInsert(
                ['driver_id' => $driverId, 'start_time' => $startTime],
                [
                    'vehicle_id' => $vehicleId,
                    'shift_type' => $shiftType,
                    'start_km' => $startKm,
                    'start_gps_lat' => $gpsLat,
                    'start_gps_lng' => $gpsLng,
                    'updated_at' => now(),
                    'created_at' => $startTime,
                ]
            );

            return DB::table('driver_shifts')
                ->where('driver_id', $driverId)->where('start_time', $startTime)
                ->value('id');
        };

        // ═══════════════════════════════════════════════════════════════
        // ORDER 1: 20C-08678 (Hoàng) — Đang giao hàng (delivering status)
        // Route: Kho HN → KCN Bắc Thăng Long → KCN Nội Bài
        // ═══════════════════════════════════════════════════════════════
        $shift1 = $findOrCreateShift($driverHoang, $vehicle1, $now->copy()->setTime(6, 0), 12350, 21.0285, 105.8542);
        DB::table('vehicles')->where('id', $vehicle1)->update(['status' => 'running']);

        $oid1 = $makeOrder(
            'ORD-HN-001', $vehicle1, $driverHoang, 'delivering',
            'BN', $customerASGL, 'SEMV',
            $now->copy()->setTime(7, 0), 'Linh kiện điện tử', 25, 1200.5,
            $now->copy()->setTime(6, 30),
        );
        $dp1_1 = $makeDP($oid1, 1, 'BN', 'Kho Bắc Thăng Long', '0912345001');

        $makeCP($oid1, $driverHoang, $shift1, 'started', $now->copy()->setTime(7, 0, 0), $dp1_1, 12352, 21.0285, 105.8542, 'Xuất phát từ bãi xe Cầu Giấy');
        $makeCP($oid1, $driverHoang, $shift1, 'arrived_pickup', $now->copy()->setTime(7, 35, 0), $dp1_1, 12368, 21.0900, 105.8100, 'Đến SEMV lấy hàng linh kiện');
        $makeCP($oid1, $driverHoang, $shift1, 'left_pickup', $now->copy()->setTime(8, 0, 0), $dp1_1, 12368, 21.1000, 105.8000, 'Đã load 25 kiện, rời SEMV');
        $makeCP($oid1, $driverHoang, $shift1, 'arrived_delivery', $now->copy()->setTime(8, 30, 0), $dp1_1, 12382, 21.1385, 105.7652, 'Đến KCN Bắc Thăng Long, chờ dỡ');

        // Current position: đang trên đường đến Nội Bài
        $makeCP($oid1, $driverHoang, $shift1, 'left_pickup', $now->copy()->setTime(9, 15, 0), $dp1_1, 12395, 21.1800, 105.7900, 'Đã dỡ xong BN, đang đi Nội Bài tiếp');

        // ═══════════════════════════════════════════════════════════════
        // ORDER 2: 99H-00948 (Toàn) — Vừa bắt đầu (started status)
        // Route: Kho → ICD Mỹ Đình → Cảng HN
        // ═══════════════════════════════════════════════════════════════
        $shift2 = $findOrCreateShift($driverToan, $vehicle2, $now->copy()->setTime(7, 30), 28500, 21.0500, 105.8600);

        $oid2 = $makeOrder(
            'ORD-HN-002', $vehicle2, $driverToan, 'started',
            'TN', $customerBACHHO, 'ICD',
            $now->copy()->setTime(8, 0), 'Hàng bách hóa', 40, 800.0,
            $now->copy()->setTime(7, 45),
        );
        $dp2_1 = $makeDP($oid2, 1, 'TN', 'Kho Tây Nam - Thạch Thất', '0912345002');

        $makeCP($oid2, $driverToan, $shift2, 'started', $now->copy()->setTime(8, 0, 0), $dp2_1, 28502, 21.0500, 105.8600, 'Bắt đầu ca từ ICD Mỹ Đình');
        $makeCP($oid2, $driverToan, $shift2, 'arrived_pickup', $now->copy()->setTime(8, 20, 0), $dp2_1, 28510, 21.0400, 105.8400, 'Đến ICD lấy hàng');
        $makeCP($oid2, $driverToan, $shift2, 'left_pickup', $now->copy()->setTime(8, 40, 0), $dp2_1, 28510, 21.0450, 105.8500, 'Đã lấy hàng xong');

        // ═══════════════════════════════════════════════════════════════
        // ORDER 3: 51D-23456 (Phúc) — Đang giao (delivering status)
        // Route: Cảng HN → KCN Bắc Ninh → KCN Tiên Sơn
        // ═══════════════════════════════════════════════════════════════
        $shift3 = $findOrCreateShift($driverPhuc, $vehicle3, $now->copy()->setTime(6, 0), 45021, 21.0515, 105.8578);
        DB::table('vehicles')->where('id', $vehicle3)->update(['status' => 'running']);

        $oid3 = $makeOrder(
            'ORD-HN-003', $vehicle3, $driverPhuc, 'delivering',
            'BN', $customerKLDNA, 'CATLA',
            $now->copy()->setTime(6, 30), 'Thực phẩm đông lạnh', 60, 2000.0,
            $now->copy()->setTime(6, 0),
        );
        $dp3_1 = $makeDP($oid3, 1, 'BN', 'Kho Bắc Ninh', '0912345003');
        $dp3_2 = $makeDP($oid3, 2, 'TN', 'Kho Tiên Sơn', '0912345004');

        $makeCP($oid3, $driverPhuc, $shift3, 'started', $now->copy()->setTime(6, 15, 0), $dp3_1, 45022, 21.0515, 105.8578, 'Xuất phát từ cảng HN');
        $makeCP($oid3, $driverPhuc, $shift3, 'arrived_pickup', $now->copy()->setTime(6, 40, 0), $dp3_1, 45035, 21.0515, 105.8578, 'Lấy hàng đông lạnh tại cảng');
        $makeCP($oid3, $driverPhuc, $shift3, 'left_pickup', $now->copy()->setTime(7, 0, 0), $dp3_1, 45035, 21.0600, 105.8600, 'Rời cảng, đi Bắc Ninh');
        $makeCP($oid3, $driverPhuc, $shift3, 'arrived_delivery', $now->copy()->setTime(8, 10, 0), $dp3_1, 45078, 21.1861, 106.0763, 'Đến Bắc Ninh, giao 30 kiện');

        // Current position: đang đi tiếp sang Tiên Sơn
        $makeCP($oid3, $driverPhuc, $shift3, 'left_pickup', $now->copy()->setTime(9, 0, 0), $dp3_1, 45090, 21.1500, 106.0200, 'Đã giao xong BN, đi tiếp Tiên Sơn');

        // ═══════════════════════════════════════════════════════════════
        // ORDER 4: 51E-34567 (Bình) — Sẵn sàng (assigned status)
        // ── Xe lạnh chờ lệnh
        // ═══════════════════════════════════════════════════════════════
        $shift4 = $findOrCreateShift($driverBinh, $vehicle5, $now->copy()->setTime(8, 0), 15600, 21.0285, 105.8542);

        $oid4 = $makeOrder(
            'ORD-HN-004', $vehicle5, $driverBinh, 'assigned',
            'NBA', $customerBIGC, 'NBO',
            $now->copy()->setTime(10, 0), 'Hàng siêu thị BIGC', 80, 3000.0,
        );
        $makeDP($oid4, 1, 'NBA', 'Kho Nội Bài A', '0912345005');
        $makeDP($oid4, 2, 'NBO', 'Kho Nội Bài ngoài', '0912345006');

        // ═══════════════════════════════════════════════════════════════
        // ORDER 5: 29H-12345 (Tuấn) — Đã giao xong (completed status - hôm qua)
        // Route: ICD → KCN Quang Minh → KCN Phố Nối
        // ═══════════════════════════════════════════════════════════════
        $yesterday = $now->copy()->subDay();
        $shift5 = $findOrCreateShift(
            driverId: $driverHoang, vehicleId: $vehicle6,
            startTime: $yesterday->copy()->setTime(6, 0), startKm: 62000,
            gpsLat: 21.0300, gpsLng: 105.7800
        );

        $oid5 = $makeOrder(
            'ORD-HN-005', $vehicle6, $driverHoang, 'completed',
            'BN', $customerASGL, 'ICD',
            $yesterday->copy()->setTime(6, 30), 'Container hàng xuất', 1, 15000.0,
            $yesterday->copy()->setTime(6, 0),
        );
        $dp5_1 = $makeDP($oid5, 1, 'BN', 'Kho Quang Minh - Mê Linh', '0912345007');

        $makeCP($oid5, $driverHoang, $shift5, 'started', $yesterday->copy()->setTime(6, 30, 0), $dp5_1, 62002, 21.0300, 105.7800, 'Nhận container rỗng tại ICD');
        $makeCP($oid5, $driverHoang, $shift5, 'arrived_pickup', $yesterday->copy()->setTime(7, 15, 0), $dp5_1, 62025, 21.0900, 105.8100, 'Đến điểm lấy container');
        $makeCP($oid5, $driverHoang, $shift5, 'left_pickup', $yesterday->copy()->setTime(7, 45, 0), $dp5_1, 62025, 21.1000, 105.8000, 'Container đã đóng seal');
        $makeCP($oid5, $driverHoang, $shift5, 'arrived_delivery', $yesterday->copy()->setTime(9, 30, 0), $dp5_1, 62095, 21.1778, 105.8195, 'Đến KCN Quang Minh, chờ dỡ');
        $makeCP($oid5, $driverHoang, $shift5, 'completed', $yesterday->copy()->setTime(11, 0, 0), $dp5_1, 62120, 20.9450, 106.0220, 'Đã giao container tại Phố Nối, ký nhận đủ');

        // ═══════════════════════════════════════════════════════════════
        // ORDER 6: 51G-56789 (Hải) — Vừa giao xong (delivered status)
        // Route: Kho HN → KCN Thạch Thất
        // ═══════════════════════════════════════════════════════════════
        $shift6 = $findOrCreateShift(
            driverId: $driverAn, vehicleId: $vehicle7,
            startTime: $now->copy()->setTime(5, 0), startKm: 3400,
            gpsLat: 21.0285, gpsLng: 105.8542
        );

        $oid6 = $makeOrder(
            'ORD-HN-006', $vehicle7, $driverAn, 'delivered',
            'TN', $customerBACHHO, 'SEMV',
            $now->copy()->setTime(5, 30), 'Hàng tiêu dùng Bách Hóa Xanh', 35, 900.0,
            $now->copy()->setTime(5, 0),
        );
        $dp6_1 = $makeDP($oid6, 1, 'TN', 'Kho Thạch Thất', '0912345008');

        $makeCP($oid6, $driverAn, $shift6, 'started', $now->copy()->setTime(5, 30, 0), $dp6_1, 3402, 21.0285, 105.8542, 'Bắt đầu từ bãi xe');
        $makeCP($oid6, $driverAn, $shift6, 'arrived_pickup', $now->copy()->setTime(5, 50, 0), $dp6_1, 3410, 21.0285, 105.8542, 'Lấy hàng BHX');
        $makeCP($oid6, $driverAn, $shift6, 'left_pickup', $now->copy()->setTime(6, 10, 0), $dp6_1, 3410, 21.0300, 105.8400, 'Rời kho');
        $makeCP($oid6, $driverAn, $shift6, 'arrived_delivery', $now->copy()->setTime(7, 0, 0), $dp6_1, 3435, 20.9950, 105.6400, 'Đến Thạch Thất');
        $makeCP($oid6, $driverAn, $shift6, 'completed', $now->copy()->setTime(8, 0, 0), $dp6_1, 3445, 20.9950, 105.6400, 'Hoàn thành chuyến');

        // ═══════════════════════════════════════════════════════════════
        // Update vehicle statuses to match
        // ═══════════════════════════════════════════════════════════════
        DB::table('vehicles')->where('id', $vehicle2)->update(['status' => 'running']);
        DB::table('vehicles')->where('id', $vehicle5)->update(['status' => 'on']);
        DB::table('vehicles')->where('id', $vehicle6)->update(['status' => 'on']);
        DB::table('vehicles')->where('id', $vehicle7)->update(['status' => 'on']);

        // ═══════════════════════════════════════════════════════════════
        // Update order DP statuses for delivered/completed orders
        // ═══════════════════════════════════════════════════════════════
        DB::table('order_delivery_points')
            ->whereIn('order_id', [$oid5, $oid6])
            ->update(['status' => 'delivered']);

        // ═══════════════════════════════════════════════════════════════
        // Summary
        // ═══════════════════════════════════════════════════════════════
        echo "Created/updated orders:\n";
        foreach ($createdOrders as $code => $id) {
            $o = DB::table('orders')->find($id);
            $dp = DB::table('order_delivery_points')->where('order_id', $id)->count();
            $cp = DB::table('trip_checkpoints')->where('order_id', $id)->count();
            $v = DB::table('vehicles')->find($o->vehicle_id);
            echo "  {$code} | {$o->status} | {$v->plate_number} | {$dp} DPs | {$cp} CPs\n";
        }

        echo "\nDone. Map will now show Hanoi-based routes with GPS checkpoints.\n";
    }
}
