<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds all operational tables with data matching the current database.
 *
 * Targets: users 12 | customers 10 | locations 10 | vehicles 10
 *          order_categories 9 | order_templates 10
 *          orders 25 | order_delivery_points ~50 | driver_shifts 12+
 *          driver_schedules 1
 *          vehicle_documents 10 | vehicle_maintenance_schedules 10
 *          vehicle_maintenance_jobs 12
 */
class DemoOperationsSeeder extends Seeder
{
    private array $userIds = [];

    private array $customerIds = [];

    private array $locationIds = [];

    private array $vehicleIds = [];

    private array $orderCategoryIds = [];

    private array $orderIds = [];

    private array $shiftIds = [];

    public function run(): void
    {
        $now = now();
        $pwd = Hash::make('password');

        // ═══════════════════════════════════════════════════════════════
        // 1. USERS — 8 drivers + 2 dispatchers + 1 admin
        // ═══════════════════════════════════════════════════════════════
        $users = [
            ['Lê Đình Hoàng',    'driver.hoang@example.com', '079203001234', ['ADR', 'Forklift']],
            ['Phạm Khánh Toàn',  'driver.toan@example.com',  '079203001235', ['ADR']],
            ['Lê Hoàng Phúc',    'driver.phuc@example.com',  '079203001236', []],
            ['Nguyễn Văn An',    'driver.an@example.com',    '079203001237', ['ADR', 'Container']],
            ['Trần Văn Bình',    'driver.binh@example.com',  '079203001238', []],
            ['Hoàng Minh Tuấn',  'driver.tuan@example.com',  '079203001239', ['ADR']],
            ['Ngô Quang Hải',    'driver.hai@example.com',   '079203001240', []],
            ['Đỗ Thanh Sơn',     'driver.son@example.com',   '079203001241', ['Container', 'Forklift']],
            ['Điều hành 1',      'ops1@example.com',         null, []],
            ['Điều hành 2',      'ops2@example.com',         null, []],
        ];

        foreach ($users as $u) {
            DB::table('users')->upsert(
                [[
                    'name' => $u[0], 'email' => $u[1], 'password' => $pwd,
                    'email_verified_at' => $now,
                    'cccd' => $u[2],
                    'cccd_issue_date' => $u[2] ? '2020-01-01' : null,
                    'certificates' => $u[3] ? json_encode($u[3]) : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]],
                ['email'],
                ['name', 'password', 'email_verified_at', 'cccd', 'cccd_issue_date', 'certificates', 'updated_at'],
            );
        }

        $this->userIds = DB::table('users')->whereIn('email', array_column($users, 1))->pluck('id', 'email')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 2. CUSTOMERS — 10 (HCMC-based)
        // ═══════════════════════════════════════════════════════════════
        $customers = [
            ['ASGL',   'ASG Logistics',         '028-3822-1111', 'KCN VSIP 1, Bình Dương',                'OPS ASGL'],
            ['ALSC',   'ALSC Vietnam',          '028-3844-2222', 'Sân bay Tân Sơn Nhất, TP.HCM',           'Cargo ALSC'],
            ['BIGCGV', 'Siêu thị BigC Gò Vấp',   '028-3895-6789', '242 Quang Trung, Gò Vấp, TP.HCM',        'Kho BigC'],
            ['KLDNA',  'Kho Lạnh Đông Nam Á',    '028-3756-0123', 'KCN Tân Bình, TP.HCM',                   'Kho lạnh'],
            ['MMMEGA', 'MM Mega Market',         '028-3777-8899', 'KCN Linh Trung, Thủ Đức',                'Kho MM'],
            ['SATRA',  'Satra Logistics',        '028-3933-4455', 'Cảng Cát Lái, Q.2, TP.HCM',             'Cảng vụ'],
            ['POTLOG', 'Potato Logistics VN',    '028-3999-1122', 'ICD Tân Cảng, Q.Bình Thạnh',             'Kho ICD'],
            ['BACHHO', 'Bách Hóa Xanh',          '028-3555-6677', 'Kho tổng Bình Dương',                    'Kho BHX'],
            ['DHLVN',  'DHL Vietnam',            '028-3838-9000', 'Tân Sơn Nhất, cargo terminal',           'Air Ops'],
            ['FEDEXV', 'FedEx VN',               '028-3737-8000', 'Long Thành, Đồng Nai',                   'Hub FedEx'],
        ];

        foreach ($customers as $c) {
            DB::table('customers')->upsert(
                [['code' => $c[0], 'name' => $c[1], 'phone' => $c[2], 'address' => $c[3], 'contact_person' => $c[4], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]],
                ['code'],
                ['name', 'phone', 'address', 'contact_person', 'is_active', 'updated_at'],
            );
        }

        $this->customerIds = DB::table('customers')->whereIn('code', array_column($customers, 0))->pluck('id', 'code')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 3. LOCATIONS — 10 (HCMC/Southern) with lat/lng for map features
        // ═══════════════════════════════════════════════════════════════
        $locations = [
            // code, name, address, loc_type, lat, lng
            ['SEMV',  'SEMV',              'Khu Công Nghệ Cao, TP. Thủ Đức',    'pickup',    10.8554, 106.7913],
            ['ALSC',  'ALSC',              'Ga hàng hóa Tân Sơn Nhất',          'delivery',  10.8188, 106.6580],
            ['NBA',   'Nội Bài A',         'Nội Bài, Hà Nội',                   'warehouse', 21.2179, 105.8038],
            ['NBO',   'Nội Bài kho ngoài', 'Sóc Sơn, Hà Nội',                   'warehouse', 21.2599, 105.8302],
            ['TN',    'Tây Nam',           '15 Đại lộ Bình Dương, Thủ Dầu Một', 'delivery',  10.9805, 106.6514],
            ['BN',    'Bắc Nam',           '123 Nguyễn Văn Linh, Q.7, TP.HCM',  'delivery',  10.7290, 106.7203],
            ['PROV',  'Đi tỉnh',           'Đa tuyến liên tỉnh',                'other',     10.8231, 106.6297],
            ['CATLA', 'Cát Lái',           'Cảng Cát Lái, Q.2, TP.HCM',         'pickup',    10.7633, 106.7784],
            ['ICD',   'ICD Tân Cảng',      'Q.Bình Thạnh, TP.HCM',              'warehouse', 10.8086, 106.7072],
            ['TANSO', 'Tân Sơn Nhất',      'Sân bay Tân Sơn Nhất',              'pickup',    10.8188, 106.6520],
        ];

        foreach ($locations as $l) {
            DB::table('locations')->upsert(
                [['code' => $l[0], 'name' => $l[1], 'address' => $l[2], 'loc_type' => $l[3], 'lat' => $l[4], 'lng' => $l[5], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]],
                ['code'],
                ['name', 'address', 'loc_type', 'lat', 'lng', 'is_active', 'updated_at'],
            );
        }

        $this->locationIds = DB::table('locations')->whereIn('code', array_column($locations, 0))->pluck('id', 'code')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 4. VEHICLES — 10
        // ═══════════════════════════════════════════════════════════════
        $vehicles = [
            ['20C-08678', 'normal',          'ASGT',     'Hyundai',    2022, 7.0,  'Diesel',  12345.5, 'Porter - Xe tuyến HK',       'on',      'company'],
            ['99H-00948', 'normal',          'ASGT',     'Toyota',     2021, 5.0,  'Diesel',  28500.8, 'Hiace - Xe tuyến city',     'on',      'company'],
            ['51D-23456', 'container',       'Tam Bảo',  'Hino',       2020, 15.0, 'Diesel',  45172.0, 'Container lạnh 300S',        'running', 'rent'],
            ['51C-12345', 'normal',          'ASGT',     'Hyundai',    2022, 5.0,  'Diesel',  8200.3,  'Porter - Xe dự phòng',      'on',      'company'],
            ['51E-34567', 'cold',            'ASGT',     'Isuzu',      2023, 8.0,  'Diesel',  15600.0, 'Xe lạnh Isuzu',              'on',      'company'],
            ['29H-12345', 'container',       'Thuê Bắc', 'Hino',       2021, 18.0, 'Diesel',  62000.0, 'Container thuê Bắc',         'running', 'rent'],
            ['51G-56789', 'flatbed',         'ASGT',     'Hyundai',    2024, 10.0, 'Diesel',  3400.0,  'Flatbed mới',                'on',      'company'],
            ['60C-11111', 'bat_wing',        'ASGT',     'Hino',       2020, 12.0, 'Diesel',  38000.0, 'Bat-wing Hino',              'bdsc',    'company'],
            ['51H-22222', 'anti_vibration',  'ASGT',     'Mitsubishi', 2023, 6.0,  'Diesel',  9200.0,  'Xe chống rung',              'off',     'company'],
            ['51K-33333', 'other',           'Thuê Nam', 'Dongfeng',   2019, 9.0,  'Diesel',  72000.0, 'Xe thuê ngoài',              'off',     'rent'],
        ];

        foreach ($vehicles as $v) {
            DB::table('vehicles')->upsert(
                [[
                    'plate_number' => $v[0],
                    'vehicle_type' => $v[1], 'owner' => $v[2], 'make' => $v[3],
                    'model_year' => $v[4], 'load_capacity' => $v[5], 'fuel_type' => $v[6],
                    'current_mileage' => $v[7],
                    'is_active' => true, 'status' => $v[9], 'type' => $v[10],
                    'notes' => $v[8], 'created_at' => $now, 'updated_at' => $now,
                ]],
                ['plate_number'],
                ['vehicle_type', 'owner', 'make', 'model_year', 'load_capacity', 'fuel_type', 'current_mileage', 'is_active', 'status', 'type', 'notes', 'updated_at'],
            );
        }

        $this->vehicleIds = DB::table('vehicles')->whereIn('plate_number', array_column($vehicles, 0))->pluck('id', 'plate_number')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 5. ORDER CATEGORIES — 9
        // ═══════════════════════════════════════════════════════════════
        $categories = [
            ['type' => 'HHHK',     'code' => 'NBA',      'name' => 'Nội bộ A',        'sort_order' => 1],
            ['type' => 'HHHK',     'code' => 'TN',       'name' => 'Tây Nam',         'sort_order' => 2],
            ['type' => 'HHHK',     'code' => 'BN',       'name' => 'Bắc Nam',         'sort_order' => 3],
            ['type' => 'HHHK',     'code' => 'NBO',      'name' => 'Nội bộ',          'sort_order' => 4],
            ['type' => 'external', 'code' => 'PROVINCE', 'name' => 'Đi tỉnh',         'sort_order' => 5],
            ['type' => 'external', 'code' => 'EXC',      'name' => 'Hàng xuất khẩu',  'sort_order' => 0],
            ['type' => 'external', 'code' => 'IMC',      'name' => 'Hàng nhập khẩu',  'sort_order' => 0],
            ['type' => 'HHHK',     'code' => 'DONGNAI',  'name' => 'Đồng Nai',        'sort_order' => 0],
            ['type' => 'external', 'code' => 'LGST',     'name' => 'Logistics nội địa', 'sort_order' => 0],
        ];

        foreach ($categories as $cat) {
            DB::table('order_categories')->upsert(
                [[
                    'type' => $cat['type'], 'code' => $cat['code'],
                    'name' => $cat['name'], 'sort_order' => $cat['sort_order'],
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]],
                ['type', 'code'],
                ['name', 'sort_order', 'is_active', 'updated_at'],
            );
        }

        $catRows = DB::table('order_categories')->get(['id', 'code', 'type']);
        $this->orderCategoryIds = [];
        foreach ($catRows as $r) {
            $this->orderCategoryIds[$r->type.'|'.$r->code] = $r->id;
        }

        // Shorthand ID resolvers
        $uid = fn (?string $e) => $e ? ($this->userIds[$e] ?? null) : null;
        $oid = fn (?string $c) => $c ? ($this->orderCategoryIds[$c] ?? null) : null;
        $lid = fn (?string $c) => $c ? ($this->locationIds[$c] ?? null) : null;
        $cid = fn (?string $c) => $c ? ($this->customerIds[$c] ?? null) : null;
        $vid = fn (?string $p) => $p ? ($this->vehicleIds[$p] ?? null) : null;

        // ═══════════════════════════════════════════════════════════════
        // 6. ORDER TEMPLATES — 10
        // ═══════════════════════════════════════════════════════════════
        $templates = [
            ['HHHK giao SEMV→ALSC',   'HHHK',  'TN',       'ASGL',   'SEMV',  ['ALSC']],
            ['HHHK giao NBA→NBO',     'HHHK',  'NBO',      'ASGL',   'NBA',   ['NBO']],
            ['External giao BN→TN',   'external', 'PROVINCE', 'KLDNA', 'BN',   ['TN']],
            ['Xuất khẩu cảng',        'external', 'EXC',    'POTLOG', 'ICD',   ['CATLA']],
            ['Nhập khẩu nội địa',     'external', 'IMC',    'SATRA',  'CATLA', ['BN', 'TN']],
            ['Giao hàng Đồng Nai',    'HHHK',  'DONGNAI',  'DHLVN',  'TANSO', ['ICD']],
            ['Logistics BHX',         'external', 'LGST',  'BACHHO', 'PROV',  ['BN']],
            ['Chuyến lạnh MM',        'external', 'PROVINCE', 'MMMEGA', 'PROV', ['BN']],
            ['Giao FedEx Hub',        'HHHK',  'BN',       'FEDEXV', 'TANSO', ['ICD']],
            ['ASGL nội địa thường',   'HHHK',  'TN',       'ASGL',   'SEMV',  ['ALSC', 'TN']],
        ];

        foreach ($templates as $i => $t) {
            DB::table('order_templates')->updateOrInsert(
                ['name' => $t[0]],
                [
                    'order_data' => json_encode([
                        'order_type_code' => $t[1],
                        'order_category_code' => $t[2],
                        'customer_code' => $t[3],
                        'pickup_location_code' => $t[4],
                        'delivery_location_codes' => $t[5],
                        'notes' => 'Template #'.($i + 1),
                    ]),
                    'is_active' => true,
                    'created_by' => $uid('ops1@example.com'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        // ═══════════════════════════════════════════════════════════════
        // 7. ORDERS — 4 records per status = ~48 orders
        // ═══════════════════════════════════════════════════════════════
        $statuses = [
            'draft', 'assigned', 'sent', 'started', 'arrived_pickup',
            'delivering', 'arrived_delivery', 'delivered', 'completed',
            'cancelled', 'driver_swap',
        ];

        $typeCatCombos = [
            ['HHHK', 'TN'], ['HHHK', 'BN'], ['HHHK', 'NBA'], ['HHHK', 'NBO'],
            ['HHHK', 'DONGNAI'],
            ['external', 'PROVINCE'], ['external', 'EXC'], ['external', 'IMC'], ['external', 'LGST'],
        ];

        $pickupLocations = ['SEMV', 'CATLA', 'TANSO', 'ICD', 'NBA'];
        $deliveryLocations = ['ALSC', 'TN', 'BN', 'NBO', 'ICD', 'CATLA', 'PROV'];
        $customers = ['ASGL', 'ALSC', 'BIGCGV', 'KLDNA', 'MMMEGA', 'SATRA', 'POTLOG', 'BACHHO', 'DHLVN', 'FEDEXV'];
        $vehicles = ['20C-08678', '99H-00948', '51D-23456', '51C-12345', '51E-34567', '29H-12345', '51G-56789', '60C-11111', '51H-22222', '51K-33333'];
        $drivers = ['driver.hoang', 'driver.toan', 'driver.phuc', 'driver.an', 'driver.binh', 'driver.tuan', 'driver.hai', 'driver.son'];
        $cargoNames = [
            'Hàng điện tử', 'Linh kiện ô tô', 'Thực phẩm đông lạnh', 'Vải may mặc',
            'Hàng tiêu dùng', 'Thiết bị y tế', 'Hàng không - Dây đai', 'Container seal tạm',
            'Express documents', 'Hàng bách hóa', 'Nông sản', 'Hàng xuất khẩu',
            'Hàng nhập khẩu', 'FedEx parcel', 'DHL express', 'Hàng đông lạnh NK',
            'Container xuất cảng', 'BHX hàng khô', 'Đồng Nai gạo', 'ICD nhập hàng',
        ];

        $orderDefs = [];
        $deliveryMaps = [];
        $orderIdx = 0;

        foreach ($statuses as $status) {
            for ($i = 0; $i < 4; $i++) {
                $dayOffset = match ($status) {
                    'draft', 'assigned' => rand(0, 1),
                    'sent', 'started' => rand(-1, 0),
                    'arrived_pickup', 'delivering', 'arrived_delivery', 'driver_swap' => rand(-2, 0),
                    'delivered', 'completed' => rand(-7, -1),
                    'cancelled' => rand(-5, -1),
                    default => rand(-3, 0),
                };

                $orderDate = $now->copy()->addDays($dayOffset);
                $code = 'ORD-'.$orderDate->format('Ymd').'-'.str_pad(++$orderIdx, 3, '0', STR_PAD_LEFT);
                $combo = $typeCatCombos[array_rand($typeCatCombos)];
                $pickup = $pickupLocations[array_rand($pickupLocations)];
                $dels = array_slice(array_diff($deliveryLocations, [$pickup]), 0, rand(1, 2));
                $hasVehicle = ! in_array($status, ['draft'], true);
                $vehiclePlate = $hasVehicle ? $vehicles[array_rand($vehicles)] : null;
                $driverEmail = $hasVehicle ? $drivers[array_rand($drivers)] : null;

                $orderDefs[] = [
                    $code,
                    $combo[0].'|'.$combo[1],
                    $customers[array_rand($customers)],
                    $pickup,
                    $vehiclePlate,
                    $driverEmail,
                    $status,
                    $cargoNames[array_rand($cargoNames)],
                    'Order #'.$orderIdx.' - '.$status,
                    $dayOffset,
                ];

                $deliveryMaps[$code] = collect($dels)->map(fn ($d, $idx) => [$d, $idx + 1])->toArray();
            }
        }

        foreach ($orderDefs as $d) {
            $orderDate = $now->copy()->addDays($d[9]);
            $status = $d[6];
            $sentAt = in_array($status, ['draft', 'assigned'], true) ? null : $orderDate->copy()->setTime(rand(5, 18), rand(0, 59), 0);

            DB::table('orders')->updateOrInsert(
                ['order_code' => $d[0]],
                [
                    'type' => explode('|', $d[1])[0],
                    'order_category_id' => $oid($d[1]),
                    'customer_id' => $cid($d[2]),
                    'cargo_name' => $d[7],
                    'cargo_type' => 'GCR',
                    'total_packages' => rand(5, 50),
                    'total_weight' => rand(200, 5000) / 1000,
                    'pickup_location_id' => $lid($d[3]),
                    'pickup_address' => $d[3],
                    'pickup_contact' => 'Kho '.$d[3],
                    'pickup_phone' => '0901'.rand(100000, 999999),
                    'planned_loading_at' => $orderDate->copy()->setTime(rand(6, 20), rand(0, 59), 0),
                    'vehicle_id' => $vid($d[4]),
                    'driver_id' => $d[5] ? $uid($d[5].'@example.com') : null,
                    'status' => $status,
                    'is_return_trip' => false,
                    'created_by' => $d[5] ? $uid($d[5].'@example.com') : $uid('ops1@example.com'),
                    'sent_at' => $sentAt,
                    'notes' => $d[8],
                    'created_at' => $orderDate,
                    'updated_at' => $now,
                ],
            );
        }

        $this->orderIds = DB::table('orders')->whereIn('order_code', array_column($orderDefs, 0))->pluck('id', 'order_code')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 8. ORDER DELIVERY POINTS — 1-2 per order
        // ═══════════════════════════════════════════════════════════════
        foreach ($deliveryMaps as $code => $dps) {
            $oid = $this->orderIds[$code];
            $orderDate = DB::table('orders')->where('id', $oid)->value('planned_loading_at');
            foreach ($dps as $dp) {
                DB::table('order_delivery_points')->updateOrInsert(
                    ['order_id' => $oid, 'sequence' => $dp[1]],
                    [
                        'location_id' => $lid($dp[0]),
                        'address' => $dp[0],
                        'contact_person' => 'Kho '.$dp[0],
                        'contact_phone' => '0902'.rand(100000, 999999),
                        'status' => 'pending',
                        'created_at' => $orderDate,
                        'updated_at' => $now,
                    ],
                );
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 9. DRIVER SHIFTS — 12
        // ═══════════════════════════════════════════════════════════════
        $shifts = [
            ['driver.hoang', '20C-08678', 'full',          0, 6,  12345.5],
            ['driver.toan',  '99H-00948', 'morning_half',  0, 6,  28500.8],
            ['driver.phuc',  '51D-23456', 'night_half',   -1, 17, 45021.0],
            ['driver.an',    '51C-12345', 'full',          0, 6,  8200.3],
            ['driver.binh',  '51E-34567', 'full',          0, 5,  15600.0],
            ['driver.tuan',  '29H-12345', 'full',         -1, 8,  62000.0],
            ['driver.hai',   '51G-56789', 'morning_half',  0, 7,  3400.0],
            ['driver.son',   '60C-11111', 'full',         -2, 6,  38000.0],
            ['driver.hoang', '20C-08678', 'full',         -1, 6,  12400.0],
            ['driver.phuc',  '51D-23456', 'night_half',   -2, 17, 45080.0],
            ['driver.toan',  '99H-00948', 'full',         -2, 6,  28550.0],
            ['driver.hai',   '51G-56789', 'full',         -1, 6,  3450.0],
        ];

        foreach ($shifts as $s) {
            $shiftDate = $now->copy()->addDays($s[3])->setTime($s[4], 0, 0);
            $email = $s[0].'@example.com';
            DB::table('driver_shifts')->updateOrInsert(
                ['driver_id' => $uid($email), 'start_time' => $shiftDate],
                [
                    'vehicle_id' => $vid($s[1]),
                    'shift_type' => $s[2],
                    'start_km' => $s[5],
                    'start_gps_lat' => 10.70 + (rand(-500, 500) / 10000),
                    'start_gps_lng' => 106.65 + (rand(-500, 500) / 10000),
                    'updated_at' => $now,
                    'created_at' => $shiftDate,
                ],
            );
        }

        $allShifts = DB::table('driver_shifts')->get();
        $this->shiftIds = [];
        foreach ($allShifts as $s) {
            $email = DB::table('users')->where('id', $s->driver_id)->value('email');
            $key = $email.'|'.$s->start_time;
            $this->shiftIds[$key] = $s->id;
        }

        // ═══════════════════════════════════════════════════════════════
        // 11. VEHICLE DOCUMENTS — 10
        // ═══════════════════════════════════════════════════════════════
        $plateNumbers = array_keys($this->vehicleIds);
        for ($i = 0; $i < 10; $i++) {
            $vid = $this->vehicleIds[$plateNumbers[$i % count($plateNumbers)]];
            $docType = $i < 5 ? 'registration' : 'inspection';
            DB::table('vehicle_documents')->updateOrInsert(
                ['vehicle_id' => $vid, 'doc_type' => $docType, 'certificate_number' => 'DOC-'.str_pad($i + 1, 4, '0', STR_PAD_LEFT)],
                [
                    'issued_by' => 'Cục Đăng Kiểm VN',
                    'issued_date' => $now->copy()->subMonths(rand(1, 24)),
                    'expiry_date' => $now->copy()->addMonths(rand(1, 12)),
                    'renewal_cost' => rand(500000, 5000000),
                    'status' => 'active',
                    'notes' => 'Giấy tờ xe #'.($i + 1),
                    'created_by' => $uid('ops1@example.com'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        // ═══════════════════════════════════════════════════════════════
        // 12. VEHICLE MAINTENANCE SCHEDULES — 10
        // ═══════════════════════════════════════════════════════════════
        $scheduleDefs = [
            ['Thay nhớt định kỳ',         'periodic_maintenance', 'by_km',     5000,  null],
            ['Kiểm tra phanh',            'inspection',           'by_km',     10000, null],
            ['Đăng kiểm',                 'inspection',           'by_date',   null,  6],
            ['Bảo dưỡng điều hòa lạnh',   'periodic_maintenance', 'by_km',     15000, null],
            ['Thay lốp',                  'repair',               'by_km',     40000, null],
            ['Bảo hiểm TNDS',             'inspection',           'by_date',   null,  12],
            ['Kiểm tra khí thải',         'inspection',           'by_km',     20000, null],
            ['Bảo dưỡng tổng quát',       'periodic_maintenance', 'both',      30000, 12],
            ['Thay dây curoa',            'repair',               'by_km',     60000, null],
            ['Kiểm tra PCCC',             'inspection',           'by_date',   null,  3],
        ];

        foreach ($scheduleDefs as $i => $sd) {
            $vid = $this->vehicleIds[$plateNumbers[$i % count($plateNumbers)]];
            DB::table('vehicle_maintenance_schedules')->updateOrInsert(
                ['name' => $sd[0], 'vehicle_id' => $vid],
                [
                    'job_type' => $sd[1],
                    'trigger_type' => $sd[2],
                    'km_interval' => $sd[3],
                    'km_current' => $sd[3] ? rand(1000, (int) $sd[3] - 500) : null,
                    'km_next_trigger' => $sd[3] ? (int) $sd[3] : null,
                    'km_remind_before' => $sd[3] ? 500 : null,
                    'date_interval_days' => $sd[4] ? $sd[4] * 30 : null,
                    'date_next_trigger' => $sd[4] ? $now->copy()->addMonths($sd[4]) : null,
                    'date_remind_before_days' => $sd[4] ? 7 : null,
                    'estimated_cost' => rand(500000, 10000000),
                    'garage' => 'Garage ASGT',
                    'is_mandatory' => in_array($sd[1], ['registration', 'insurance', 'inspection']),
                    'auto_create_job' => true,
                    'is_active' => true,
                    'alert_status' => 'ok',
                    'created_by' => $uid('ops1@example.com'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        // ═══════════════════════════════════════════════════════════════
        // 13. VEHICLE MAINTENANCE JOBS — 12
        // ═══════════════════════════════════════════════════════════════
        $jobDefs = [
            ['Thay nhớt 20C-08678',        'periodic_maintenance',  'completed',   -3],
            ['Kiểm tra phanh 51D-23456',   'inspection',            'in_progress', -1],
            ['Đăng kiểm 99H-00948',        'registration',          'pending',      2],
            ['Bảo dưỡng lạnh 51E-34567',   'periodic_maintenance',  'pending',      5],
            ['Thay lốp 29H-12345',         'repair',                'completed',   -7],
            ['Bảo hiểm 51G-56789',         'insurance',             'completed',  -14],
            ['Kiểm tra khí thải 51C-12345', 'inspection',            'completed',  -10],
            ['Bảo dưỡng tổng quát 60C-11111', 'periodic_maintenance', 'overdue',      -5],
            ['Thay dây curoa 99H-00948',   'repair',                'in_progress',  -2],
            ['PCCC 20C-08678',             'inspection',            'pending',       1],
            ['Sửa máy lạnh 51D-23456',     'repair',                'completed',   -21],
            ['Đăng kiểm 51H-22222',        'registration',          'cancelled',    -4],
        ];

        foreach ($jobDefs as $i => $jd) {
            $vid = $this->vehicleIds[$plateNumbers[$i % count($plateNumbers)]];
            $planned = $now->copy()->addDays($jd[3]);
            DB::table('vehicle_maintenance_jobs')->updateOrInsert(
                ['title' => $jd[0], 'vehicle_id' => $vid],
                [
                    'job_type' => $jd[1],
                    'priority' => $jd[2] === 'overdue' ? 'urgent' : ($jd[2] === 'in_progress' ? 'high' : 'medium'),
                    'description' => 'Công việc bảo dưỡng #'.($i + 1),
                    'planned_date' => $planned,
                    'remind_before_days' => 3,
                    'estimated_cost' => rand(500000, 15000000),
                    'actual_cost' => $jd[2] === 'completed' ? rand(500000, 15000000) : null,
                    'garage' => 'Garage ASGT',
                    'technician' => $jd[2] === 'completed' ? 'Kỹ thuật viên A' : null,
                    'km_at_service' => $jd[2] === 'completed' ? rand(10000, 80000) : null,
                    'status' => $jd[2],
                    'completed_at' => $jd[2] === 'completed' ? $planned : null,
                    'created_by' => $uid('ops1@example.com'),
                    'created_at' => $planned,
                    'updated_at' => $now,
                ],
            );
        }

        $this->printSummary();
    }

    private function printSummary(): void
    {
        $tables = ['users', 'customers', 'locations', 'vehicles', 'order_categories', 'order_templates', 'orders', 'order_delivery_points', 'driver_shifts', 'vehicle_documents', 'vehicle_maintenance_schedules', 'vehicle_maintenance_jobs'];
        $this->command->info('DemoOperationsSeeder done.');
        foreach ($tables as $t) {
            $c = DB::table($t)->count();
            $this->command->info('  '.str_pad($t, 32).$c);
        }
    }
}
