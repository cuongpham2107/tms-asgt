<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds all operational tables with realistic demo data.
 *
 * Targets: users 10 | customers 10 | locations 10 | vehicles 10
 *          order_types 2 (domain) | order_categories 10 | order_templates 10
 *          orders 25 | order_delivery_points ~50 | driver_shifts 12+
 *          driver_swaps 6 | trip_checkpoints ~60 | trip_photos 5
 *          vehicle_documents 10 | vehicle_maintenance_schedules 10
 *          vehicle_maintenance_jobs 12
 */
class DemoOperationsSeeder extends Seeder
{
    private array $userIds = [];

    private array $customerIds = [];

    private array $locationIds = [];

    private array $vehicleIds = [];

    private array $orderTypeIds = [];

    private array $orderCategoryIds = [];

    private array $orderIds = [];

    private array $shiftIds = [];

    public function run(): void
    {
        $now = now();
        $pwd = Hash::make('password');

        $this->call(OrderTypeCategorySeeder::class);

        // ═══════════════════════════════════════════════════════════════
        // 1. USERS — 10 drivers + dispatchers
        // ═══════════════════════════════════════════════════════════════
        $users = [
            ['Lê Đình Hoàng',    'driver.hoang@example.com', '079203001234', '2020-03-15', ['ADR', 'Forklift']],
            ['Phạm Khánh Toàn',  'driver.toan@example.com',  '079203001235', '2021-07-20', ['ADR']],
            ['Lê Hoàng Phúc',    'driver.phuc@example.com',  '079203001236', '2019-11-01', []],
            ['Nguyễn Văn An',    'driver.an@example.com',    '079203001237', '2022-01-10', ['ADR', 'Container']],
            ['Trần Văn Bình',    'driver.binh@example.com',  '079203001238', '2020-06-05', []],
            ['Hoàng Minh Tuấn',  'driver.tuan@example.com',  '079203001239', '2021-09-18', ['ADR']],
            ['Ngô Quang Hải',    'driver.hai@example.com',   '079203001240', '2023-02-22', []],
            ['Đỗ Thanh Sơn',     'driver.son@example.com',   '079203001241', '2018-05-30', ['Container', 'Forklift']],
            ['Điều hành 1',      'ops1@example.com',         null, null, []],
            ['Điều hành 2',      'ops2@example.com',         null, null, []],
        ];

        foreach ($users as $u) {
            DB::table('users')->upsert(
                [[
                    'name' => $u[0], 'email' => $u[1], 'password' => $pwd,
                    'email_verified_at' => $now,
                    'cccd' => $u[2], 'cccd_issue_date' => $u[3],
                    'certificates' => $u[4] ? json_encode($u[4]) : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]],
                ['email'],
                ['name', 'password', 'email_verified_at', 'cccd', 'cccd_issue_date', 'certificates', 'updated_at']
            );
        }

        $this->userIds = DB::table('users')->whereIn('email', array_column($users, 1))->pluck('id', 'email')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 2. CUSTOMERS — 10
        // ═══════════════════════════════════════════════════════════════
        $customers = [
            ['ASGL',   'ASG Logistics',         '028-3822-1111', 'KCN VSIP 1, Bình Dương',               'OPS ASGL'],
            ['ALSC',   'ALSC Vietnam',          '028-3844-2222', 'Sân bay Tân Sơn Nhất, TP.HCM',          'Cargo ALSC'],
            ['BIGCGV', 'Siêu thị BigC Gò Vấp',  '028-3895-6789', '242 Quang Trung, Gò Vấp, TP.HCM',       'Kho BigC'],
            ['KLDNA',  'Kho Lạnh Đông Nam Á',   '028-3756-0123', 'KCN Tân Bình, TP.HCM',                  'Kho lạnh'],
            ['MMMEGA', 'MM Mega Market',        '028-3777-8899', 'KCN Linh Trung, Thủ Đức',               'Kho MM'],
            ['SATRA',  'Satra Logistics',       '028-3933-4455', 'Cảng Cát Lái, Q.2, TP.HCM',             'Cảng vụ'],
            ['POTLOG', 'Potato Logistics VN',   '028-3999-1122', 'ICD Tân Cảng, Q.Bình Thạnh',            'Kho ICD'],
            ['BACHHO', 'Bách Hóa Xanh',         '028-3555-6677', 'Kho tổng Bình Dương',                   'Kho BHX'],
            ['DHLVN',  'DHL Vietnam',           '028-3838-9000', 'Tân Sơn Nhất, cargo terminal',          'Air Ops'],
            ['FEDEXV', 'FedEx VN',              '028-3737-8000', 'Long Thành, Đồng Nai',                  'Hub FedEx'],
        ];

        foreach ($customers as $c) {
            DB::table('customers')->upsert(
                [['code' => $c[0], 'name' => $c[1], 'phone' => $c[2], 'address' => $c[3], 'contact_person' => $c[4], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]],
                ['code'],
                ['name', 'phone', 'address', 'contact_person', 'is_active', 'updated_at']
            );
        }

        $this->customerIds = DB::table('customers')->whereIn('code', array_column($customers, 0))->pluck('id', 'code')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 3. LOCATIONS — 10
        // ═══════════════════════════════════════════════════════════════
        $locations = [
            ['SEMV', 'SEMV',               'Khu Công Nghệ Cao, TP. Thủ Đức',      'pickup'],
            ['ALSC', 'ALSC',               'Ga hàng hóa Tân Sơn Nhất',            'delivery'],
            ['NBA',  'Nội Bài A',          'Nội Bài, Hà Nội',                     'warehouse'],
            ['NBO',  'Nội Bài kho ngoài',  'Sóc Sơn, Hà Nội',                     'warehouse'],
            ['TN',   'Tây Nam',            '15 Đại lộ Bình Dương, Thủ Dầu Một',  'delivery'],
            ['BN',   'Bắc Nam',            '123 Nguyễn Văn Linh, Q.7, TP.HCM',   'delivery'],
            ['PROV', 'Đi tỉnh',            'Đa tuyến liên tỉnh',                   'other'],
            ['CATLA', 'Cát Lái',           'Cảng Cát Lái, Q.2, TP.HCM',           'pickup'],
            ['ICD',  'ICD Tân Cảng',       'Q.Bình Thạnh, TP.HCM',                'warehouse'],
            ['TANSO', 'Tân Sơn Nhất',      'Sân bay Tân Sơn Nhất',                'pickup'],
        ];

        foreach ($locations as $l) {
            DB::table('locations')->upsert(
                [['code' => $l[0], 'name' => $l[1], 'address' => $l[2], 'loc_type' => $l[3], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]],
                ['code'],
                ['name', 'address', 'loc_type', 'is_active', 'updated_at']
            );
        }

        $this->locationIds = DB::table('locations')->whereIn('code', array_column($locations, 0))->pluck('id', 'code')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 4. VEHICLES — 10
        // ═══════════════════════════════════════════════════════════════
        $vehicles = [
            ['20C-08678', 'normal',       'ASGT',     'Hyundai',  2022, 7.0,  'Diesel',  12345.5, 'Porter - Xe tuyến HK',   'driver.hoang', 'on',      'company'],
            ['99H-00948', 'normal',       'ASGT',     'Toyota',   2021, 5.0,  'Diesel',  28500.8, 'Hiace - Xe tuyến city', 'driver.toan',  'on',      'company'],
            ['51D-23456', 'container',    'Tam Bảo',  'Hino',     2020, 15.0, 'Diesel',  45172.0, 'Container lạnh 300S',    'driver.phuc',  'running', 'rent'],
            ['51C-12345', 'normal',       'ASGT',     'Hyundai',  2022, 5.0,  'Diesel',  8200.3,  'Porter - Xe dự phòng',  'driver.an',    'on',      'company'],
            ['51E-34567', 'cold',         'ASGT',     'Isuzu',    2023, 8.0,  'Diesel',  15600.0, 'Xe lạnh Isuzu',          'driver.binh',  'on',      'company'],
            ['29H-12345', 'container',    'Thuê Bắc', 'Hino',     2021, 18.0, 'Diesel',  62000.0, 'Container thuê Bắc',     'driver.tuan',  'running', 'rent'],
            ['51G-56789', 'flatbed',      'ASGT',     'Hyundai',  2024, 10.0, 'Diesel',  3400.0,  'Flatbed mới',            'driver.hai',   'on',      'company'],
            ['60C-11111', 'bat_wing',     'ASGT',     'Hino',     2020, 12.0, 'Diesel',  38000.0, 'Bat-wing Hino',          'driver.son',   'bdsc',    'company'],
            ['51H-22222', 'anti_vibration', 'ASGT',    'Mitsubishi', 2023, 6.0, 'Diesel',  9200.0,  'Xe chống rung',          null,           'off',     'company'],
            ['51K-33333', 'other',        'Thuê Nam', 'Dongfeng',  2019, 9.0,  'Diesel',  72000.0, 'Xe thuê ngoài',          null,           'off',     'rent'],
        ];

        foreach ($vehicles as $v) {
            $driverId = $v[9] ? ($this->userIds[$v[9].'@example.com'] ?? null) : null;
            DB::table('vehicles')->upsert(
                [[
                    'plate_number' => $v[0], 'registration_number' => 'DK-'.str_replace('-', '', $v[0]),
                    'vehicle_type' => $v[1], 'owner' => $v[2], 'make' => $v[3],
                    'model_year' => $v[4], 'load_capacity' => $v[5], 'fuel_type' => $v[6],
                    'current_mileage' => $v[7], 'current_driver_id' => $driverId,
                    'is_active' => true, 'status' => $v[10], 'type' => $v[11],
                    'notes' => $v[8], 'created_at' => $now, 'updated_at' => $now,
                ]],
                ['plate_number'],
                ['registration_number', 'vehicle_type', 'owner', 'make', 'model_year', 'load_capacity', 'fuel_type', 'current_mileage', 'current_driver_id', 'is_active', 'status', 'type', 'notes', 'updated_at']
            );
        }

        $this->vehicleIds = DB::table('vehicles')->whereIn('plate_number', array_column($vehicles, 0))->pluck('id', 'plate_number')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 5. ORDER CATEGORIES — ensure 10 (5 from OrderTypeCategorySeeder + 5 more)
        // ═══════════════════════════════════════════════════════════════
        $this->orderTypeIds = DB::table('order_types')->whereIn('code', ['HHHK', 'external'])->pluck('id', 'code')->toArray();

        $extraCategories = [
            ['order_type_id' => $this->orderTypeIds['HHHK'],     'code' => 'BN',   'name' => 'Bắc Nam',          'description' => 'Tuyến Bắc Nam'],
            ['order_type_id' => $this->orderTypeIds['external'], 'code' => 'EXC',  'name' => 'Hàng xuất khẩu',   'description' => 'Hàng xuất cảng'],
            ['order_type_id' => $this->orderTypeIds['external'], 'code' => 'IMC',  'name' => 'Hàng nhập khẩu',   'description' => 'Hàng nhập từ cảng'],
            ['order_type_id' => $this->orderTypeIds['HHHK'],     'code' => 'DONGNAI', 'name' => 'Đồng Nai',      'description' => 'Tuyến Đồng Nai'],
            ['order_type_id' => $this->orderTypeIds['external'], 'code' => 'LGST', 'name' => 'Logistics nội địa', 'description' => 'Vận chuyển nội địa tổng hợp'],
        ];

        foreach ($extraCategories as $cat) {
            DB::table('order_categories')->updateOrInsert(
                ['order_type_id' => $cat['order_type_id'], 'code' => $cat['code']],
                ['name' => $cat['name'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $catRows = DB::table('order_categories')->get(['id', 'code', 'order_type_id']);
        $this->orderCategoryIds = [];
        foreach ($catRows as $r) {
            $typeCode = DB::table('order_types')->where('id', $r->order_type_id)->value('code');
            $this->orderCategoryIds[$typeCode.'|'.$r->code] = $r->id;
        }

        // Shorthand closures for ID resolution
        $uid = fn (?string $e) => $e ? ($this->userIds[$e] ?? null) : null;
        $oid = fn (?string $c) => $c ? ($this->orderCategoryIds[$c] ?? null) : null;
        $lid = fn (?string $c) => $c ? ($this->locationIds[$c] ?? null) : null;
        $cid = fn (?string $c) => $c ? ($this->customerIds[$c] ?? null) : null;
        $vid = fn (?string $p) => $p ? ($this->vehicleIds[$p] ?? null) : null;

        // ═══════════════════════════════════════════════════════════════
        // 6. ORDER TEMPLATES — 10
        // ═══════════════════════════════════════════════════════════════
        $templates = [
            ['HHHK giao SEMV→ALSC',   'HHHK',  'TN',     'ASGL',  'SEMV',  ['ALSC']],
            ['HHHK giao NBA→NBO',     'HHHK',  'NBO',    'ASGL',  'NBA',   ['NBO']],
            ['External giao BN→TN',   'external', 'PROVINCE', 'KLDNA', 'BN', ['TN']],
            ['Xuất khẩu cảng',        'external', 'EXC',   'POTLOG', 'ICD',   ['CATLA']],
            ['Nhập khẩu nội địa',     'external', 'IMC',   'SATRA', 'CATLA', ['BN', 'TN']],
            ['Giao hàng Đồng Nai',    'HHHK',  'DONGNAI', 'DHLVN', 'TANSO', ['ICD']],
            ['Logistics BHX',         'external', 'LGST', 'BACHHO', 'PROV',  ['BN']],
            ['Chuyến lạnh MM',        'external', 'PROVINCE', 'MMMEGA', 'PROV', ['BN']],
            ['Giao FedEx Hub',        'HHHK',  'BN',     'FEDEXV', 'TANSO', ['ICD']],
            ['ASGL nội địa thường',   'HHHK',  'TN',     'ASGL',  'SEMV',  ['ALSC', 'TN']],
        ];

        foreach ($templates as $i => $t) {
            $catKey = $t[1].'|'.$t[2];
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
                ]
            );
        }

        // ═══════════════════════════════════════════════════════════════
        // 7. ORDERS — 25 orders across dates today..today-7
        // ═══════════════════════════════════════════════════════════════

        $orderDefs = [
            // code, type|cat, customer, pickup, vehicle, driver, status, cargo, notes, dayOffset
            ['ORD-2026-0516-001', 'HHHK|TN',      'ASGL',   'SEMV', '20C-08678', 'driver.hoang', 'started',          'Hàng không - Dây đai',              'Dây đai',              0],
            ['ORD-2026-0516-002', 'HHHK|BN',      'DHLVN',  'TANSO', '51E-34567', 'driver.binh', 'started',          'Express documents',                 'DHL express',          0],
            ['ORD-2026-0516-003', 'external|PROVINCE', 'KLDNA', 'BN',  '51D-23456', 'driver.phuc', 'started',           'Hàng đông lạnh NK',                 'Hàng lạnh',            0],
            ['ORD-2026-0516-004', 'HHHK|DONGNAI', 'FEDEXV', 'TANSO', '51G-56789', 'driver.hai',  'delivering',       'FedEx parcel bulk',                 'FedEx',                0],
            ['ORD-2026-0516-005', 'external|LGST', 'BACHHO', 'PROV',  '29H-12345', 'driver.tuan', 'arrived_delivery', 'BHX hàng khô',                      'BHX',                  0],
            ['ORD-2026-0516-006', 'external|EXC', 'POTLOG', 'ICD',   '51C-12345', 'driver.an',   'sent',             'Container xuất cảng',               'Xuất cảng',            0],
            ['ORD-2026-0516-007', 'HHHK|NBO',     'ASGL',   'NBA',   null,        null,          'draft',            'Hàng NBA→NBO',                      'Draft',                0],
            ['ORD-2026-0516-008', 'external|IMC', 'SATRA',  'CATLA', '99H-00948', 'driver.toan', 'arrived_pickup',   'Hàng nhập cảng Cát Lái',            'Nhập khẩu',            0],
            ['ORD-2026-0515-001', 'HHHK|TN',      'ASGL',   'SEMV',  '20C-08678', 'driver.hoang', 'completed',        'Dây đai SEMV→ALSC',                 'Dây đai',             -1],
            ['ORD-2026-0515-002', 'external|PROVINCE', 'MMMEGA', 'PROV', '51D-23456', 'driver.phuc', 'completed',          'Container seal tạm',                'MM Mega',             -1],
            ['ORD-2026-0515-003', 'HHHK|BN',      'DHLVN',  'TANSO', '51E-34567', 'driver.binh', 'completed',         'DHL express southbound',            'DHL',                 -1],
            ['ORD-2026-0515-004', 'external|LGST', 'BACHHO', 'PROV',  '29H-12345', 'driver.tuan', 'completed',         'BHX miền Đông',                     'BHX',                 -1],
            ['ORD-2026-0515-005', 'HHHK|DONGNAI', 'ASGL',   'SEMV',  '99H-00948', 'driver.toan', 'completed',         'Đồng Nai gạo',                      'Gạo',                 -1],
            ['ORD-2026-0514-001', 'external|PROVINCE', 'KLDNA', 'BN',  '51D-23456', 'driver.phuc', 'completed',        'Hàng lạnh NK',                      'Lạnh NK',             -2],
            ['ORD-2026-0514-002', 'HHHK|TN',      'ASGL',   'SEMV',  '20C-08678', 'driver.hoang', 'completed',        'Hàng không thường',                 'HK thường',           -2],
            ['ORD-2026-0514-003', 'external|EXC', 'POTLOG', 'ICD',   '60C-11111', 'driver.son',  'completed',        'Xuất khẩu container',               'Xuất khẩu',           -2],
            ['ORD-2026-0514-004', 'external|IMC', 'SATRA',  'CATLA', '51C-12345', 'driver.an',   'cancelled',        'Nhập khẩu — hủy do KH',             'Hủy',                 -2],
            ['ORD-2026-0514-005', 'HHHK|BN',      'FEDEXV', 'TANSO', '51G-56789', 'driver.hai',  'completed',         'FedEx Bắc Nam',                     'FedEx',               -2],
            ['ORD-2026-0513-001', 'HHHK|NBO',     'ASGL',   'NBA',   '20C-08678', 'driver.hoang', 'completed',        'NBA→NBO thường',                    'Thường',              -3],
            ['ORD-2026-0513-002', 'external|LGST', 'BACHHO', 'PROV',  '29H-12345', 'driver.tuan', 'completed',         'BHX miền Tây',                      'BHX',                 -3],
            ['ORD-2026-0513-003', 'HHHK|DONGNAI', 'DHLVN',  'TANSO', '51E-34567', 'driver.binh', 'completed',         'DHL Đồng Nai',                      'DHL',                 -3],
            ['ORD-2026-0512-001', 'external|PROVINCE', 'MMMEGA', 'PROV', '51D-23456', 'driver.phuc', 'completed',          'Hàng MM miền Nam',                  'MM Mega',             -4],
            ['ORD-2026-0512-002', 'HHHK|TN',      'ASGL',   'SEMV',  '99H-00948', 'driver.toan', 'completed',         'SEMV→ALSC định kỳ',                 'Định kỳ',             -4],
            ['ORD-2026-0512-003', 'external|IMC', 'POTLOG', 'ICD',   '51C-12345', 'driver.an',   'completed',         'ICD nhập hàng',                     'ICD',                 -4],
            ['ORD-2026-0511-001', 'HHHK|BN',      'FEDEXV', 'TANSO', '51G-56789', 'driver.hai',  'completed',         'FedEx tuần trước',                  'FedEx',               -5],
        ];

        foreach ($orderDefs as $d) {
            $orderDate = $now->copy()->addDays($d[9]);
            DB::table('orders')->updateOrInsert(
                ['order_code' => $d[0]],
                [
                    'order_type_id' => $this->orderTypeIds[explode('|', $d[1])[0]],
                    'order_category_id' => $oid($d[1]),
                    'customer_id' => $cid($d[2]),
                    'cargo_name' => $d[7],
                    'cargo_type' => 'GCR',
                    'total_packages' => rand(5, 50),
                    'total_weight' => rand(200, 5000),
                    'pickup_location_id' => $lid($d[3]),
                    'pickup_address' => $d[3],
                    'pickup_contact' => 'Kho '.$d[3],
                    'pickup_phone' => '0901'.rand(100000, 999999),
                    'planned_loading_at' => $orderDate->copy()->setTime(rand(6, 20), rand(0, 59), 0),
                    'vehicle_id' => $vid($d[4]),
                    'driver_id' => $d[5] ? $uid($d[5].'@example.com') : null,
                    'status' => $d[6],
                    'is_return_trip' => false,
                    'created_by' => $uid('ops1@example.com'),
                    'sent_at' => in_array($d[6], ['draft']) ? null : $orderDate->copy()->setTime(rand(5, 18), rand(0, 59), 0),
                    'notes' => $d[8],
                    'created_at' => $orderDate,
                    'updated_at' => $now,
                ]
            );
        }

        $this->orderIds = DB::table('orders')->whereIn('order_code', array_column($orderDefs, 0))->pluck('id', 'order_code')->toArray();

        // ═══════════════════════════════════════════════════════════════
        // 8. ORDER DELIVERY POINTS — 1-3 per order
        // ═══════════════════════════════════════════════════════════════
        $deliveryMaps = [
            'ORD-2026-0516-001' => [['ALSC', 1]],
            'ORD-2026-0516-002' => [['ICD', 1], ['BN', 2]],
            'ORD-2026-0516-003' => [['TN', 1]],
            'ORD-2026-0516-004' => [['ICD', 1]],
            'ORD-2026-0516-005' => [['BN', 1], ['TN', 2]],
            'ORD-2026-0516-006' => [['CATLA', 1]],
            'ORD-2026-0516-007' => [['NBO', 1]],
            'ORD-2026-0516-008' => [['TN', 1], ['BN', 2]],
            'ORD-2026-0515-001' => [['ALSC', 1]],
            'ORD-2026-0515-002' => [['TN', 1], ['BN', 2]],
            'ORD-2026-0515-003' => [['ICD', 1]],
            'ORD-2026-0515-004' => [['BN', 1]],
            'ORD-2026-0515-005' => [['ICD', 1], ['TN', 2]],
            'ORD-2026-0514-001' => [['TN', 1]],
            'ORD-2026-0514-002' => [['ALSC', 1]],
            'ORD-2026-0514-003' => [['CATLA', 1]],
            'ORD-2026-0514-004' => [['BN', 1]],
            'ORD-2026-0514-005' => [['ICD', 1], ['TN', 2]],
            'ORD-2026-0513-001' => [['NBO', 1]],
            'ORD-2026-0513-002' => [['BN', 1]],
            'ORD-2026-0513-003' => [['ICD', 1], ['TN', 2]],
            'ORD-2026-0512-001' => [['TN', 1]],
            'ORD-2026-0512-002' => [['ALSC', 1]],
            'ORD-2026-0512-003' => [['BN', 1], ['TN', 2]],
            'ORD-2026-0511-001' => [['ICD', 1]],
        ];

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
                    ]
                );
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 9. DRIVER SHIFTS — 12+
        // ═══════════════════════════════════════════════════════════════
        $shifts = [
            ['driver.hoang', '20C-08678', 'full',        0, 6,  12345.5],
            ['driver.toan',  '99H-00948', 'morning_half', 0, 6,  28500.8],
            ['driver.phuc',  '51D-23456', 'night_half', -1, 17, 45021.0],
            ['driver.an',    '51C-12345', 'full',        0, 6,  8200.3],
            ['driver.binh',  '51E-34567', 'full',        0, 5,  15600.0],
            ['driver.tuan',  '29H-12345', 'full',       -1, 8,  62000.0],
            ['driver.hai',   '51G-56789', 'morning_half', 0, 7,  3400.0],
            ['driver.son',   '60C-11111', 'full',       -2, 6,  38000.0],
            ['driver.hoang', '20C-08678', 'full',       -1, 6,  12400.0],
            ['driver.phuc',  '51D-23456', 'night_half', -2, 17, 45080.0],
            ['driver.toan',  '99H-00948', 'full',       -2, 6,  28550.0],
            ['driver.hai',   '51G-56789', 'full',       -1, 6,  3450.0],
        ];

        foreach ($shifts as $s) {
            $shiftDate = $now->copy()->addDays($s[3])->setTime($s[4], 0, 0);
            DB::table('driver_shifts')->updateOrInsert(
                ['driver_id' => $uid($s[0].'@example.com'), 'start_time' => $shiftDate],
                [
                    'vehicle_id' => $vid($s[1]),
                    'shift_type' => $s[2],
                    'start_km' => $s[5],
                    'start_gps_lat' => 10.75 + (rand(-500, 500) / 10000),
                    'start_gps_lng' => 106.68 + (rand(-500, 500) / 10000),
                    'updated_at' => $now,
                    'created_at' => $shiftDate,
                ]
            );
        }

        // Resolve shift IDs
        $allShifts = DB::table('driver_shifts')->get();
        $this->shiftIds = [];
        foreach ($allShifts as $s) {
            $email = DB::table('users')->where('id', $s->driver_id)->value('email');
            $key = $email.'|'.$s->start_time;
            $this->shiftIds[$key] = $s->id;
        }

        // ═══════════════════════════════════════════════════════════════
        // 10. DRIVER SWAPS — 6
        // ═══════════════════════════════════════════════════════════════
        $swaps = [
            ['ORD-2026-0516-001', 'driver.hoang', 'driver.toan',  'shift_handover', 'Đảo lái theo bàn giao ca'],
            ['ORD-2026-0516-003', 'driver.phuc',  'driver.an',    'shift_handover', 'Bàn giao ca container lạnh'],
            ['ORD-2026-0516-004', 'driver.hai',   'driver.binh',  'shift_handover', 'Đảo lái FedEx tuyến ĐN'],
            ['ORD-2026-0515-002', 'driver.phuc',  'driver.an',    'shift_handover', 'Đảo lái MM Mega'],
            ['ORD-2026-0514-003', 'driver.son',   'driver.hai',   'cargo_not_unloaded', 'Chưa dỡ hết hàng'],
            ['ORD-2026-0513-001', 'driver.hoang', 'driver.toan',  'other',         'Lái chính nghỉ ốm'],
        ];

        foreach ($swaps as $sw) {
            $fromEmail = $sw[1].'@example.com';
            $toEmail = $sw[2].'@example.com';
            $fromShiftId = DB::table('driver_shifts')
                ->where('driver_id', $uid($fromEmail))
                ->latest('start_time')
                ->value('id');

            DB::table('driver_swaps')->updateOrInsert(
                ['order_id' => $this->orderIds[$sw[0]], 'created_at' => $now],
                [
                    'from_driver_id' => $uid($fromEmail),
                    'to_driver_id' => $uid($toEmail),
                    'from_shift_id' => $fromShiftId,
                    'handover_km' => rand(8000, 70000) + (rand(0, 999) / 10),
                    'reason' => $sw[3],
                    'note' => $sw[4],
                    'created_by' => $uid('ops1@example.com'),
                ]
            );
        }

        // ═══════════════════════════════════════════════════════════════
        // 11. TRIP CHECKPOINTS — for active/recent orders
        // ═══════════════════════════════════════════════════════════════
        $checkpointOrders = [
            'ORD-2026-0516-001' => ['driver.hoang', ['started', 'arrived_pickup', 'left_pickup', 'arrived_delivery'], 0],
            'ORD-2026-0516-002' => ['driver.binh',  ['started', 'arrived_pickup', 'left_pickup'], 0],
            'ORD-2026-0516-003' => ['driver.phuc',  ['started', 'arrived_pickup', 'left_pickup', 'driver_swap', 'arrived_delivery'], 0],
            'ORD-2026-0516-004' => ['driver.hai',   ['started', 'arrived_pickup', 'left_pickup', 'driver_swap', 'arrived_delivery', 'arrived_delivery'], 0],
            'ORD-2026-0516-005' => ['driver.tuan',  ['started', 'arrived_pickup', 'left_pickup', 'arrived_delivery', 'arrived_delivery', 'completed'], 0],
            'ORD-2026-0516-008' => ['driver.toan',  ['started', 'arrived_pickup'], 0],
            'ORD-2026-0515-001' => ['driver.hoang', ['started', 'arrived_pickup', 'left_pickup', 'arrived_delivery', 'completed'], -1],
            'ORD-2026-0515-002' => ['driver.phuc',  ['started', 'arrived_pickup', 'left_pickup', 'driver_swap', 'arrived_delivery', 'arrived_delivery', 'completed'], -1],
            'ORD-2026-0515-003' => ['driver.binh',  ['started', 'arrived_pickup', 'left_pickup', 'arrived_delivery', 'completed'], -1],
            'ORD-2026-0515-004' => ['driver.tuan',  ['started', 'arrived_pickup', 'left_pickup', 'arrived_delivery', 'completed'], -1],
            'ORD-2026-0514-001' => ['driver.phuc',  ['started', 'arrived_pickup', 'left_pickup', 'arrived_delivery', 'completed'], -2],
            'ORD-2026-0514-002' => ['driver.hoang', ['started', 'arrived_pickup', 'left_pickup', 'arrived_delivery', 'completed'], -2],
            'ORD-2026-0514-005' => ['driver.hai',   ['started', 'arrived_pickup', 'left_pickup', 'arrived_delivery', 'arrived_delivery', 'completed'], -2],
            'ORD-2026-0513-001' => ['driver.hoang', ['started', 'driver_swap', 'arrived_pickup', 'left_pickup', 'arrived_delivery', 'completed'], -3],
        ];

        foreach ($checkpointOrders as $code => $cfg) {
            [$driverEmail, $types, $dayOff] = $cfg;
            $oid = $this->orderIds[$code];
            $dps = DB::table('order_delivery_points')->where('order_id', $oid)->orderBy('sequence')->pluck('id')->toArray();
            $dpId = $dps[0] ?? null;
            $dpId2 = $dps[1] ?? $dpId;
            $baseHour = 8;
            $driverId = $uid($driverEmail.'@example.com');
            $shiftKey = $driverEmail.'@example.com|'.$now->copy()->addDays($dayOff)->setTime(6, 0, 0);
            $shiftId = $this->shiftIds[$shiftKey] ?? DB::table('driver_shifts')->where('driver_id', $driverId)->latest('start_time')->value('id');
            $km = rand(8000, 50000);

            foreach ($types as $i => $type) {
                $useDp = ($type === 'arrived_delivery' && $dpId2 && count($types) > $i + 1 && $types[$i + 1] === 'arrived_delivery') ? $dpId : ($dpId2 ?? $dpId);
                if (count($dps) > 1 && $i >= count($types) - 1) {
                    $useDp = $dpId2;
                }

                DB::table('trip_checkpoints')->updateOrInsert(
                    ['order_id' => $oid, 'checkpoint_type' => $type, 'occurred_at' => $now->copy()->addDays($dayOff)->setTime($baseHour + $i, rand(0, 45), 0)],
                    [
                        'driver_id' => $driverId,
                        'shift_id' => $shiftId,
                        'delivery_point_id' => $useDp,
                        'km_reading' => $km + ($i * rand(5, 30)),
                        'gps_lat' => 10.70 + (($i + 1) * 0.05) + (rand(-20, 20) / 1000),
                        'gps_lng' => 106.65 + (($i + 1) * 0.01) + (rand(-20, 20) / 1000),
                        'voice_note' => $this->voiceNoteForType($type),
                        'created_at' => $now->copy()->addDays($dayOff),
                    ]
                );
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 12. TRIP PHOTOS — 5
        // ═══════════════════════════════════════════════════════════════
        $photoOrders = ['ORD-2026-0516-001', 'ORD-2026-0516-003', 'ORD-2026-0515-001', 'ORD-2026-0515-002', 'ORD-2026-0514-001'];
        foreach ($photoOrders as $code) {
            $cp = DB::table('trip_checkpoints')->where('order_id', $this->orderIds[$code])->where('checkpoint_type', 'completed')->first()
                ?? DB::table('trip_checkpoints')->where('order_id', $this->orderIds[$code])->latest('id')->first();
            if ($cp) {
                DB::table('trip_photos')->updateOrInsert(
                    ['trip_checkpoint_id' => $cp->id, 'photo_path' => 'trip-photos/'.$code.'/photo-1.jpg'],
                    ['photo_url' => null, 'created_at' => $now]
                );
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // 13. VEHICLE DOCUMENTS — 10
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
                ]
            );
        }

        // ═══════════════════════════════════════════════════════════════
        // 14. VEHICLE MAINTENANCE SCHEDULES — 10
        // ═══════════════════════════════════════════════════════════════
        $scheduleDefs = [
            ['Thay nhớt định kỳ',      'periodic_maintenance', 'by_km',     5000,  null],
            ['Kiểm tra phanh',         'inspection',           'by_km',     10000, null],
            ['Đăng kiểm',              'inspection',           'by_date',   null,  6],
            ['Bảo dưỡng điều hòa lạnh', 'periodic_maintenance', 'by_km',     15000, null],
            ['Thay lốp',               'repair',               'by_km',     40000, null],
            ['Bảo hiểm TNDS',          'inspection',           'by_date',   null,  12],
            ['Kiểm tra khí thải',      'inspection',           'by_km',     20000, null],
            ['Bảo dưỡng tổng quát',    'periodic_maintenance', 'both',      30000, 12],
            ['Thay dây curoa',         'repair',               'by_km',     60000, null],
            ['Kiểm tra PCCC',          'inspection',           'by_date',   null,  3],
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
                ]
            );
        }

        // ═══════════════════════════════════════════════════════════════
        // 15. VEHICLE MAINTENANCE JOBS — 12
        // ═══════════════════════════════════════════════════════════════
        $jobDefs = [
            ['Thay nhớt 20C-08678',        'periodic_maintenance', 'completed',  -3],
            ['Kiểm tra phanh 51D-23456',   'inspection',           'in_progress', -1],
            ['Đăng kiểm 99H-00948',        'registration',         'pending',     2],
            ['Bảo dưỡng lạnh 51E-34567',   'periodic_maintenance', 'pending',     5],
            ['Thay lốp 29H-12345',         'repair',               'completed',  -7],
            ['Bảo hiểm 51G-56789',         'insurance',            'completed',  -14],
            ['Kiểm tra khí thải 51C-12345', 'inspection',           'completed',  -10],
            ['Bảo dưỡng tổng quát 60C-11111', 'periodic_maintenance', 'overdue',    -5],
            ['Thay dây curoa 99H-00948',   'repair',               'in_progress', -2],
            ['PCCC 20C-08678',             'inspection',           'pending',     1],
            ['Sửa máy lạnh 51D-23456',     'repair',               'completed',  -21],
            ['Đăng kiểm 51H-22222',        'registration',         'cancelled',  -4],
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
                ]
            );
        }

        // ═══════════════════════════════════════════════════════════════
        $this->printSummary();
    }

    private function voiceNoteForType(string $type): string
    {
        return match ($type) {
            'started' => 'Xuất phát từ bãi, kiểm tra xe OK',
            'arrived_pickup' => 'Đến điểm lấy hàng',
            'left_pickup' => 'Đã load hàng xong, rời điểm lấy',
            'driver_swap' => 'Đảo lái theo bàn giao ca',
            'arrived_delivery' => 'Đến điểm giao hàng',
            'completed' => 'Đã giao hàng xong, khách ký nhận',
            default => '',
        };
    }

    private function printSummary(): void
    {
        $tables = ['users', 'customers', 'locations', 'vehicles', 'order_types', 'order_categories', 'order_templates', 'orders', 'order_delivery_points', 'driver_shifts', 'driver_swaps', 'trip_checkpoints', 'trip_photos', 'vehicle_documents', 'vehicle_maintenance_schedules', 'vehicle_maintenance_jobs'];
        $this->command->info('DemoOperationsSeeder done.');
        foreach ($tables as $t) {
            $c = DB::table($t)->count();
            $this->command->info('  '.str_pad($t, 32).$c);
        }
    }
}
