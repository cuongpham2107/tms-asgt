<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class FullOrderLifecycleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $pwd = Hash::make('password');

        // ── 1. Driver ─────────────────────────────────────────────────
        DB::table('users')->upsert(
            [
                'name' => 'Nguyễn Văn Demo',
                'email' => 'driver.demo@example.com',
                'password' => $pwd,
                'email_verified_at' => $now,
                'cccd' => '079203001999',
                'cccd_issue_date' => '2020-06-01',
                'certificates' => json_encode(['ADR', 'Forklift']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['email'],
            ['name', 'password', 'email_verified_at', 'cccd', 'cccd_issue_date', 'certificates', 'updated_at']
        );
        $driverId = DB::table('users')->where('email', 'driver.demo@example.com')->value('id');

        // ── 1b. Driver Role ─────────────────────────────────────────────
        $driverRole = Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
        DB::table('model_has_roles')->upsert(
            [
                'role_id' => $driverRole->id,
                'model_type' => 'App\Models\User',
                'model_id' => $driverId,
            ],
            ['role_id', 'model_id', 'model_type'],
            ['role_id', 'model_id', 'model_type']
        );

        // ── 2. Vehicle ────────────────────────────────────────────────
        DB::table('vehicles')->upsert(
            [
                'plate_number' => '99X-99999',
                'vehicle_type' => 'normal',
                'owner' => 'ASGT',
                'make' => 'Hyundai',
                'model_year' => 2024,
                'load_capacity' => 5.0,
                'fuel_type' => 'Diesel',
                'current_mileage' => 10000.0,
                'current_driver_id' => null,
                'is_active' => true,
                'status' => 'on',
                'type' => 'company',
                'notes' => 'Xe demo full lifecycle',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['plate_number'],
            ['vehicle_type', 'owner', 'make', 'model_year', 'load_capacity', 'fuel_type', 'current_mileage', 'current_driver_id', 'is_active', 'status', 'type', 'notes', 'updated_at']
        );
        $vehicleId = DB::table('vehicles')->where('plate_number', '99X-99999')->value('id');

        // ── 3. Customer ───────────────────────────────────────────────
        DB::table('customers')->upsert(
            [
                'code' => 'DEMO',
                'name' => 'Demo Customer',
                'phone' => '0909999999',
                'address' => '123 Đường Demo, Quận 1, TP.HCM',
                'contact_person' => 'Anh Demo',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['code'],
            ['name', 'phone', 'address', 'contact_person', 'is_active', 'updated_at']
        );
        $customerId = DB::table('customers')->where('code', 'DEMO')->value('id');

        // ── 4. Pickup Location ────────────────────────────────────────
        DB::table('locations')->upsert(
            [
                'code' => 'DEMO_PICKUP',
                'name' => 'Kho Demo - Điểm lấy',
                'address' => 'KCN Vsip, Bình Dương',
                'loc_type' => 'pickup',
                'lat' => 10.8554,
                'lng' => 106.7913,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['code'],
            ['name', 'address', 'loc_type', 'lat', 'lng', 'is_active', 'updated_at']
        );
        $pickupLocationId = DB::table('locations')->where('code', 'DEMO_PICKUP')->value('id');

        // ── 5. Delivery Location ──────────────────────────────────────
        DB::table('locations')->upsert(
            [
                'code' => 'DEMO_DELIVERY',
                'name' => 'Kho Demo - Điểm giao',
                'address' => 'KCN Tân Bình, TP.HCM',
                'loc_type' => 'delivery',
                'lat' => 10.8188,
                'lng' => 106.6580,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['code'],
            ['name', 'address', 'loc_type', 'lat', 'lng', 'is_active', 'updated_at']
        );
        $deliveryLocationId = DB::table('locations')->where('code', 'DEMO_DELIVERY')->value('id');

        // ── 6. Order Category ─────────────────────────────────────────
        DB::table('order_categories')->updateOrInsert(
            ['type' => 'HHHK', 'code' => 'DEMO'],
            [
                'name' => 'Demo Route',
                'sort_order' => 99,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $categoryId = DB::table('order_categories')
            ->where('type', 'HHHK')
            ->where('code', 'DEMO')
            ->value('id');

        // ── 7. Order (assigned) ───────────────────────────────────────
        $orderCode = 'ORD-DEMO-'.now()->format('Ymd');
        DB::table('orders')->upsert(
            [
                'order_code' => $orderCode,
                'type' => 'HHHK',
                'order_category_id' => $categoryId,
                'customer_id' => $customerId,
                'cargo_name' => 'Hàng hóa demo',
                'cargo_type' => 'GCR',
                'total_packages' => 10,
                'total_weight' => 2.5,
                'pickup_location_id' => $pickupLocationId,
                'pickup_address' => 'KCN Vsip, Bình Dương',
                'pickup_contact' => 'Anh Kho Demo',
                'pickup_phone' => '0909999998',
                'planned_loading_at' => $now->copy()->addHour(),
                'vehicle_id' => $vehicleId,
                'driver_id' => $driverId,
                'status' => 'sent',
                'is_return_trip' => false,
                'created_by' => $driverId,
                'notes' => 'Demo order for full lifecycle test',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['order_code'],
            ['type', 'order_category_id', 'customer_id', 'cargo_name', 'cargo_type', 'total_packages', 'total_weight', 'pickup_location_id', 'pickup_address', 'pickup_contact', 'pickup_phone', 'planned_loading_at', 'vehicle_id', 'driver_id', 'status', 'is_return_trip', 'created_by', 'notes', 'updated_at']
        );
        $orderId = DB::table('orders')->where('order_code', $orderCode)->value('id');

        // ── 8. Delivery Point ─────────────────────────────────────────
        $existingDp = DB::table('order_delivery_points')
            ->where('order_id', $orderId)
            ->where('sequence', 1)
            ->first();

        if ($existingDp) {
            DB::table('order_delivery_points')
                ->where('id', $existingDp->id)
                ->update([
                    'location_id' => $deliveryLocationId,
                    'address' => 'KCN Tân Bình, TP.HCM',
                    'contact_person' => 'Anh Kho Nhận',
                    'contact_phone' => '0909999997',
                    'updated_at' => $now,
                ]);
            $deliveryPointId = $existingDp->id;
        } else {
            $deliveryPointId = DB::table('order_delivery_points')->insertGetId([
                'order_id' => $orderId,
                'sequence' => 1,
                'location_id' => $deliveryLocationId,
                'address' => 'KCN Tân Bình, TP.HCM',
                'contact_person' => 'Anh Kho Nhận',
                'contact_phone' => '0909999997',
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── Summary ───────────────────────────────────────────────────
        $this->command->info('=== Full Order Lifecycle Demo Data Created ===');
        $this->command->info("  Driver ID:          {$driverId}");
        $this->command->info("  Vehicle ID:         {$vehicleId}");
        $this->command->info('  Vehicle:            99X-99999 (current_mileage: 10000)');
        $this->command->info("  Order ID:           {$orderId}");
        $this->command->info("  Order Code:         {$orderCode}");
        $this->command->info("  Delivery Point ID:  {$deliveryPointId}");
        $this->command->info('  Login:              driver.demo@example.com / password');
        $this->command->info('');
        $this->command->info('Run the API flow script:');
        $this->command->info('  bash database/scripts/demo-lifecycle.sh');
    }
}
