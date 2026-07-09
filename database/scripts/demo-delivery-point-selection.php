#!/usr/bin/env php
<?php

/**
 * Demo: Ghi nhận Km — Các trường hợp TH1-TH4
 *
 * TH1: Giao xong hết đơn, cùng xe về kết thúc ca
 * TH4: Chưa giao xong, bàn giao xe, hết ca (DriverSwap)
 * TH2: Giao xong, đổi xe khác về kết thúc ca
 *
 * Mô phỏng luồng mới (có 'end' checkpoint gate, manual trip complete):
 *
 * Usage:
 *   1. php artisan serve
 *   2. php database/scripts/demo-delivery-point-selection.php
 *
 * Yêu cầu: PHP 8.1+, curl, đã chạy migrate + seeder (FullOrderLifecycleSeeder)
 */

declare(strict_types=1);

// ─── Cấu hình ─────────────────────────────────────────────────────────

$baseUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');
$email = 'driver.demo@example.com';
$password = 'password';

// ─── Helpers ──────────────────────────────────────────────────────────

function request(string $method, string $url, ?string $token = null, ?array $data = null, bool $allowError = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array_filter([
            'Accept: application/json',
            $token ? "Authorization: Bearer $token" : null,
            $data ? 'Content-Type: application/json' : null,
        ]),
        CURLOPT_POSTFIELDS => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "  ⛔ cURL error: $error\n";
        exit(1);
    }

    $result = json_decode($body, true);
    $isSuccess = $status >= 200 && $status < 300;

    if (! $isSuccess && ! $allowError) {
        echo "  ⛔ HTTP $status: " . ($result['message'] ?? $body) . "\n";
        exit(1);
    }

    return ['status' => $status, 'body' => $result ?? []];
}

function step(int $num, string $label): void
{
    echo "\n─── [$num] $label ───\n";
}

function info(string $msg): void
{
    echo "  ℹ️  $msg\n";
}

function ok(string $msg): void
{
    echo "  ✅ $msg\n";
}

function fail(string $msg): void
{
    echo "  ❌ $msg\n";
    exit(1);
}

function assertTrue(bool $condition, string $msg): void
{
    if ($condition) {
        ok($msg);
    } else {
        fail($msg);
    }
}

// ─── 0. Seed + lấy IDs ───────────────────────────────────────────────
echo ">>> Đang seed dữ liệu demo...\n";
passthru('php artisan db:seed --class=FullOrderLifecycleSeeder --no-interaction 2>/dev/null', $exitCode);
if ($exitCode !== 0) {
    echo "⚠️  Seeder báo lỗi, tiếp tục với dữ liệu có sẵn...\n";
}

echo ">>> Đang truy vấn entity IDs...\n";
$idsOutput = shell_exec("php artisan tinker --execute '
\$d = DB::table(\"users\")->where(\"email\",\"driver.demo@example.com\")->value(\"id\");
\$v = DB::table(\"vehicles\")->where(\"plate_number\",\"99X-99999\")->value(\"id\");
\$v2 = DB::table(\"vehicles\")->where(\"plate_number\",\"!=\",\"99X-99999\")->where(\"is_active\",1)->value(\"id\");
\$c = DB::table(\"customers\")->where(\"code\",\"DEMO\")->value(\"id\");
\$cat = DB::table(\"order_categories\")->where(\"type\",\"HHHK\")->where(\"code\",\"DEMO\")->value(\"id\");
\$loc = DB::table(\"locations\")->where(\"code\",\"DEMO_DELIVERY\")->value(\"id\");
echo json_encode([\"driver_id\"=>\$d,\"vehicle_id\"=>\$v,\"vehicle2_id\"=>\$v2,\"customer_id\"=>\$c,\"category_id\"=>\$cat,\"delivery_location_id\"=>\$loc]);
' 2>/dev/null");

$ids = json_decode($idsOutput, true);
if (! $ids || empty($ids['driver_id'])) {
    echo "⛔ Không tìm thấy dữ liệu demo. Chạy migrate + seed trước.\n";
    exit(1);
}

$driverId = (int) $ids['driver_id'];
$vehicleId = (int) $ids['vehicle_id'];
$vehicle2Id = (int) ($ids['vehicle2_id'] ?? 0);
$deliveryLocationId = (int) $ids['delivery_location_id'];
$customerId = (int) $ids['customer_id'];
$categoryId = (int) $ids['category_id'];

info("Driver: $driverId, Vehicle: $vehicleId, Vehicle2: $vehicle2Id, DeliveryLoc: $deliveryLocationId");

// ─── Hàm tạo đơn + trip ───────────────────────────────────────────────
function createOrderWithTrip(int $vehicleId, int $driverId, int $customerId, int $categoryId, string $suffix): array
{
    global $deliveryLocationId;

    $now = date('Y-m-d H:i:s');
    $orderCode = 'ORD-DEMO-' . $suffix . '-' . date('YmdHis');

    // Tạo trip
    $tripId = (int) trim(shell_exec("php artisan tinker --execute '
\$tripId = DB::table(\"trips\")->insertGetId([
    \"trip_code\" => \"TRIP-DEMO-" . $suffix . "-" . date('YmdHis') . "\",
    \"vehicle_id\" => {$vehicleId},
    \"driver_id\" => {$driverId},
    \"status\" => \"pending\",
    \"created_at\" => \"{$now}\",
    \"updated_at\" => \"{$now}\",
]);
echo \$tripId;
' 2>/dev/null"));

    // Tạo order với trip_id
    $orderId = (int) trim(shell_exec("php artisan tinker --execute '
\$orderId = DB::table(\"orders\")->insertGetId([
    \"order_code\" => \"{$orderCode}\",
    \"type\" => \"HHHK\",
    \"order_category_id\" => {$categoryId},
    \"customer_id\" => {$customerId},
    \"trip_id\" => {$tripId},
    \"cargo_name\" => \"Hàng demo " . $suffix . "\",
    \"cargo_type\" => \"GCR\",
    \"total_packages\" => 5,
    \"total_weight\" => 1.5,
    \"pickup_address\" => \"KCN Vsip, Bình Dương\",
    \"pickup_contact\" => \"Anh Kho Demo\",
    \"pickup_phone\" => \"0909999998\",
    \"planned_loading_at\" => \"{$now}\",
    \"vehicle_id\" => {$vehicleId},
    \"driver_id\" => {$driverId},
    \"status\" => \"sent\",
    \"is_return_trip\" => false,
    \"created_by\" => {$driverId},
    \"created_at\" => \"{$now}\",
    \"updated_at\" => \"{$now}\",
]);
echo \$orderId;
' 2>/dev/null"));

    // Tạo delivery point
    $dpId = (int) trim(shell_exec("php artisan tinker --execute '
\$dpId = DB::table(\"order_delivery_points\")->insertGetId([
    \"order_id\" => {$orderId},
    \"location_id\" => {$deliveryLocationId},
    \"address\" => \"Demo Delivery Address\",
    \"sequence\" => 1,
    \"status\" => \"pending\",
    \"created_at\" => \"{$now}\",
    \"updated_at\" => \"{$now}\",
]);
echo \$dpId;
' 2>/dev/null"));

    return ['trip_id' => $tripId, 'order_id' => $orderId, 'dp_id' => $dpId];
}

// ─── Hàm gửi checkpoint (dùng đúng endpoint mới) ──────────────────────
function sendCheckpoint(string $token, int $tripId, string $type, ?int $orderId = null, ?int $dpId = null, ?int $kmReading = null): void
{
    $payload = [
        'checkpoint_type' => $type,
        'occurred_at' => date('c'),
    ];
    if ($orderId !== null) {
        $payload['order_id'] = $orderId;
    }
    if ($dpId !== null) {
        $payload['delivery_point_id'] = $dpId;
    }
    if ($kmReading !== null) {
        $payload['km_reading'] = $kmReading;
    }

    global $baseUrl;
    request('POST', "$baseUrl/api/driver/trips/{$tripId}/checkpoints", $token, $payload);
}

// ─── Cleanup helper ───────────────────────────────────────────────────
function cleanupShifts(int $driverId, int $vehicleId): void
{
    shell_exec("php artisan tinker --execute '
\$driverId = {$driverId};
\$vehicleId = {$vehicleId};
\$shiftIds = DB::table(\"driver_shifts\")->where(\"driver_id\",\$driverId)->pluck(\"id\");
DB::statement(\"PRAGMA foreign_keys = OFF\");
DB::table(\"empty_kilometers\")->whereIn(\"shift_id\",\$shiftIds)->delete();
foreach (\$shiftIds as \$sid) {
    DB::table(\"trip_checkpoints\")->where(\"shift_id\",\$sid)->delete();
}
DB::table(\"driver_swaps\")->whereIn(\"old_shift_id\",\$shiftIds)->orWhereIn(\"new_shift_id\",\$shiftIds)->delete();
DB::table(\"orders\")->whereIn(\"shift_id\",\$shiftIds)->update([\"shift_id\"=>null]);
DB::table(\"driver_shifts\")->whereIn(\"id\",\$shiftIds)->delete();
DB::statement(\"PRAGMA foreign_keys = ON\");
DB::table(\"vehicles\")->where(\"id\",\$vehicleId)->update([\"current_driver_id\"=>null,\"current_mileage\"=>20000]);
echo \"Done\";
' 2>&1");
}

// ======================================================================
// BẮT ĐẦU
// ======================================================================
cleanupShifts($driverId, $vehicleId);

// ─── Đăng nhập ────────────────────────────────────────────────────────
step(1, '🔐 Đăng nhập');

$loginResult = request('POST', "$baseUrl/api/driver/login", null, [
    'email' => $email,
    'password' => $password,
]);
$token = $loginResult['body']['token'];
ok('Token: ' . substr($token, 0, 20) . '...');

// ─── Vào ca ───────────────────────────────────────────────────────────
step(2, '🟢 Vào ca');

// Reset xe
shell_exec("php artisan tinker --execute 'DB::table(\"vehicles\")->where(\"id\",{$vehicleId})->update([\"current_mileage\"=>20000]);' 2>/dev/null");

$shiftResult = request('POST', "$baseUrl/api/driver/shifts/start", $token, [
    'vehicle_id' => $vehicleId,
    'shift_type' => 'full',
    'start_time' => date('c'),
]);
$shiftId = $shiftResult['body']['shift']['id'];
ok("Shift: $shiftId, vehicle mileage: 20000");

// ======================================================================
// TH1: Giao xong hết đơn, cùng xe về kết thúc ca
// ======================================================================
echo "\n\n══════════════════════════════════════════════════════════════\n";
echo "  TH1 — Giao xong hết, cùng xe về kết thúc ca\n";
echo "══════════════════════════════════════════════════════════════\n";

$th1 = createOrderWithTrip($vehicleId, $driverId, $customerId, $categoryId, 'TH1');

step(3, 'TH1 — Bắt đầu chuyến (started)');
sendCheckpoint($token, $th1['trip_id'], 'started');
ok('Started');

step(4, 'TH1 — Đến điểm nhận (arrived_pickup, km=20010)');
sendCheckpoint($token, $th1['trip_id'], 'arrived_pickup', kmReading: 20010);
ok('ArrivedPickup');

step(5, 'TH1 — Rời điểm nhận (left_pickup)');
sendCheckpoint($token, $th1['trip_id'], 'left_pickup');
ok('LeftPickup → Delivering');

step(6, 'TH1 — Đến điểm giao (arrived_delivery, km=20050)');
sendCheckpoint($token, $th1['trip_id'], 'arrived_delivery', orderId: $th1['order_id'], dpId: $th1['dp_id'], kmReading: 20050);
ok('ArrivedDelivery');

step(7, 'TH1 — Giao hàng xong (completed, km=20090)');
sendCheckpoint($token, $th1['trip_id'], 'completed', orderId: $th1['order_id'], dpId: $th1['dp_id'], kmReading: 20090);
$order1Status = trim(shell_exec("php artisan tinker --execute 'echo DB::table(\"orders\")->where(\"id\",{$th1['order_id']})->value(\"status\");' 2>/dev/null"));
assertTrue($order1Status === 'completed', "Order status = completed (actual: $order1Status)");
ok('Completed → order done');

step(8, 'TH1 — Kết thúc chuyến (POST /trips/{trip}/complete, end_km=20090)');
$completeResult = request('POST', "$baseUrl/api/driver/trips/{$th1['trip_id']}/complete", $token, [
    'end_km' => 20090,
]);
$trip1Status = trim(shell_exec("php artisan tinker --execute 'echo DB::table(\"trips\")->where(\"id\",{$th1['trip_id']})->value(\"status\");' 2>/dev/null"));
assertTrue($trip1Status === 'completed', "Trip status = completed (actual: $trip1Status)");
ok('Trip completed');

step(9, 'TH1 — Về điểm đỗ (end-vehicle, km=20100)');
request('POST', "$baseUrl/api/driver/shifts/{$shiftId}/end-vehicle", $token, [
    'km_reading' => 20100,
]);
ok('End vehicle → checkpoint end created');

step(10, 'TH1 — Kết thúc ca (end)');
$endResult = request('POST', "$baseUrl/api/driver/shifts/end", $token, [
    'end_time' => date('c'),
]);
$shift = $endResult['body']['shift'];
ok('End shift');

echo "\n  📊 Kết quả KM TH1:\n";
echo "    start_km  = {$shift['start_km']}\n";
echo "    end_km    = {$shift['end_km']}\n";
echo "    total_km       = {$shift['total_km']} km  (expect ≈100)\n";
echo "    total_km_loaded = {$shift['total_km_loaded']} km  (expect ≈80)\n";
echo "    total_km_empty  = {$shift['total_km_empty']} km  (expect ≈20)\n";

// ======================================================================
// TH4: Chưa giao xong, bàn giao xe, hết ca
// ======================================================================
echo "\n\n══════════════════════════════════════════════════════════════\n";
echo "  TH4 — Chưa giao xong, bàn giao xe → DriverSwap\n";
echo "══════════════════════════════════════════════════════════════\n";

// Tạo ca mới (TH4)
cleanupShifts($driverId, $vehicleId);
shell_exec("php artisan tinker --execute 'DB::table(\"vehicles\")->where(\"id\",{$vehicleId})->update([\"current_mileage\"=>30000]);' 2>/dev/null");

$shift2Result = request('POST', "$baseUrl/api/driver/shifts/start", $token, [
    'vehicle_id' => $vehicleId,
    'shift_type' => 'full',
    'start_time' => date('c'),
]);
$shiftId2 = $shift2Result['body']['shift']['id'];

$th4 = createOrderWithTrip($vehicleId, $driverId, $customerId, $categoryId, 'TH4');

step(11, 'TH4 — Bắt đầu chuyến + Đến điểm nhận (arrived_pickup, km=30010)');
sendCheckpoint($token, $th4['trip_id'], 'started');
sendCheckpoint($token, $th4['trip_id'], 'arrived_pickup', kmReading: 30010);
ok('Started + ArrivedPickup');

step(12, 'TH4 — Chưa giao xong, bấm "Kết thúc đơn hàng" để bàn giao');
info('Cách 1: Gọi POST /trips/{trip}/complete → trip.status = DriverSwap (orders chưa xong)');
$complete4Result = request('POST', "$baseUrl/api/driver/trips/{$th4['trip_id']}/complete", $token, [
    'end_km' => 30030,
]);
$trip4Status = trim(shell_exec("php artisan tinker --execute 'echo DB::table(\"trips\")->where(\"id\",{$th4['trip_id']})->value(\"status\");' 2>/dev/null"));
assertTrue($trip4Status === 'driver_swap', "Trip status = driver_swap (actual: $trip4Status)");

$order4Status = trim(shell_exec("php artisan tinker --execute 'echo DB::table(\"orders\")->where(\"id\",{$th4['order_id']})->value(\"status\");' 2>/dev/null"));
assertTrue($order4Status === 'driver_swap', "Order status = driver_swap (actual: $order4Status)");
ok('Trip → DriverSwap, orders → DriverSwap');

info('Trên web trả về trạng thái "Đảo lái" để điều hành biết và phân lái khác');

step(13, 'TH4 — Về điểm đỗ (end-vehicle, km=30050)');
request('POST', "$baseUrl/api/driver/shifts/{$shiftId2}/end-vehicle", $token, [
    'km_reading' => 30050,
]);
ok('End vehicle');

step(14, 'TH4 — Kết thúc ca');
$end2Result = request('POST', "$baseUrl/api/driver/shifts/end", $token, ['end_time' => date('c')]);
$shift2 = $end2Result['body']['shift'];

echo "\n  📊 Kết quả KM TH4 (DriverSwap giữa chừng):\n";
echo "    end_km    = {$shift2['end_km']}\n";
echo "    total_km       = {$shift2['total_km']} km\n";
echo "    total_km_loaded = {$shift2['total_km_loaded']} km\n";
echo "    total_km_empty  = {$shift2['total_km_empty']} km\n";

info('Lái mới có thể nhận trip bàn giao này và tiếp tục từ km hiện tại');

// ======================================================================
// TH2: Giao xong, đổi xe khác về kết thúc ca
// ======================================================================
if ($vehicle2Id > 0) {
    echo "\n\n══════════════════════════════════════════════════════════════\n";
    echo "  TH2 — Giao xong, đổi xe khác về kết thúc ca\n";
    echo "══════════════════════════════════════════════════════════════\n";

    cleanupShifts($driverId, $vehicleId);
    shell_exec("php artisan tinker --execute '
DB::table(\"vehicles\")->where(\"id\",{$vehicleId})->update([\"current_mileage\"=>40000]);
DB::table(\"vehicles\")->where(\"id\",{$vehicle2Id})->update([\"current_mileage\"=>50000]);
' 2>/dev/null");

    $shift3Result = request('POST', "$baseUrl/api/driver/shifts/start", $token, [
        'vehicle_id' => $vehicleId,
        'shift_type' => 'full',
        'start_time' => date('c'),
    ]);
    $shiftId3 = $shift3Result['body']['shift']['id'];

    $th2 = createOrderWithTrip($vehicleId, $driverId, $customerId, $categoryId, 'TH2');

    step(15, 'TH2 — Giao xong đơn (xe cũ)');
    sendCheckpoint($token, $th2['trip_id'], 'started');
    sendCheckpoint($token, $th2['trip_id'], 'arrived_pickup', kmReading: 40010);
    sendCheckpoint($token, $th2['trip_id'], 'arrived_delivery', orderId: $th2['order_id'], dpId: $th2['dp_id'], kmReading: 40040);
    sendCheckpoint($token, $th2['trip_id'], 'completed', orderId: $th2['order_id'], dpId: $th2['dp_id'], kmReading: 40040);
    request('POST', "$baseUrl/api/driver/trips/{$th2['trip_id']}/complete", $token, ['end_km' => 40040]);
    ok('Đơn xong, trip completed');

    step(16, 'TH2 — Về điểm đỗ xe cũ (end-vehicle, km=40070)');
    request('POST', "$baseUrl/api/driver/shifts/{$shiftId3}/end-vehicle", $token, [
        'km_reading' => 40070,
    ]);
    ok('End vehicle (xe cũ)');

    step(17, 'TH2 — Đổi sang xe mới (switch-vehicle)');
    $switchResult = request('POST', "$baseUrl/api/driver/shifts/switch-vehicle", $token, [
        'new_vehicle_id' => $vehicle2Id,
        'handover_km' => 50000,
    ]);
    ok('Switch to vehicle ' . $vehicle2Id);

    step(18, 'TH2 — Về điểm đỗ xe mới (end-vehicle, km=50010)');
    request('POST', "$baseUrl/api/driver/shifts/{$shiftId3}/end-vehicle", $token, [
        'km_reading' => 50010,
    ]);
    ok('End vehicle (xe mới)');

    step(19, 'TH2 — Kết thúc ca');
    $end3Result = request('POST', "$baseUrl/api/driver/shifts/end", $token, ['end_time' => date('c')]);
    $shift3 = $end3Result['body']['shift'];

    echo "\n  📊 Kết quả KM TH2 (đổi xe giữa ca):\n";
    echo "    end_km    = {$shift3['end_km']}\n";
    echo "    total_km       = {$shift3['total_km']} km  (2 segments riêng)\n";
    echo "    total_km_loaded = {$shift3['total_km_loaded']} km\n";
    echo "    total_km_empty  = {$shift3['total_km_empty']} km\n";
}

// ======================================================================
echo "\n\n══════════════════════════════════════════════════════════════\n";
echo "  ✅ Hoàn tất demo TH1 + TH4" . ($vehicle2Id > 0 ? ' + TH2' : '') . "!\n";
echo "══════════════════════════════════════════════════════════════\n";
