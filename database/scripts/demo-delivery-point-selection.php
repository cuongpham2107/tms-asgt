#!/usr/bin/env php
<?php

/**
 * Demo: Driver Selects Delivery Point When Order Has None
 *
 * Mô phỏng tài xế chọn điểm đến từ bảng locations khi đơn chưa có delivery point:
 *   1. Vào ca (start shift)
 *   2. Nhận đơn không có điểm đến
 *   3. started → arrived_pickup → left_pickup
 *   4. arrived_delivery KHÔNG có new_delivery_location_id → kỳ vọng 422
 *   5. arrived_delivery CÓ new_delivery_location_id → tự tạo delivery point → thành công
 *   6. completed → đơn hoàn tất
 *   7. Kết thúc ca (end shift)
 *
 * Usage:
 *   1. php artisan serve (hoặc đảm bảo app đang chạy)
 *   2. php database/scripts/demo-delivery-point-selection.php
 *
 * Yêu cầu: PHP 8.1+, curl extension, đã chạy migrate + seeder
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
        echo "  ⛔ HTTP $status: ".($result['message'] ?? $body)."\n";
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
\$c = DB::table(\"customers\")->where(\"code\",\"DEMO\")->value(\"id\");
\$cat = DB::table(\"order_categories\")->where(\"type\",\"HHHK\")->where(\"code\",\"DEMO\")->value(\"id\");
\$loc = DB::table(\"locations\")->where(\"code\",\"DEMO_DELIVERY\")->value(\"id\");
echo json_encode([\"driver_id\"=>\$d,\"vehicle_id\"=>\$v,\"customer_id\"=>\$c,\"category_id\"=>\$cat,\"delivery_location_id\"=>\$loc]);
' 2>/dev/null");

$ids = json_decode($idsOutput, true);
if (! $ids || empty($ids['driver_id'])) {
    echo "⛔ Không tìm thấy dữ liệu demo. Chạy migrate + seed trước.\n";
    exit(1);
}

$vehicleId = (int) $ids['vehicle_id'];
$deliveryLocationId = (int) $ids['delivery_location_id'];
$customerId = (int) $ids['customer_id'];
$categoryId = (int) $ids['category_id'];

info("Driver ID: {$ids['driver_id']}, Vehicle ID: $vehicleId, Delivery Location ID: $deliveryLocationId");

// ─── 1. Tạo đơn KHÔNG có delivery point ───────────────────────────────
step(1, '📝 Tạo đơn không có điểm đến');

$orderCode = 'ORD-NO-DP-'.date('YmdHis');

// Xoá đơn cũ nếu có
shell_exec("php artisan tinker --execute '
\$oc = \"{$orderCode}\";
\$old = DB::table(\"orders\")->where(\"order_code\",\$oc)->value(\"id\");
if (\$old) {
    DB::table(\"order_delivery_points\")->where(\"order_id\",\$old)->delete();
    DB::table(\"trip_checkpoints\")->where(\"order_id\",\$old)->delete();
    DB::table(\"orders\")->where(\"id\",\$old)->delete();
}
echo \"OK\";
' 2>/dev/null");

// Tạo đơn mới — KHÔNG insert delivery point
$now = date('Y-m-d H:i:s');
$orderId = (int) trim(shell_exec("php artisan tinker --execute '
DB::table(\"orders\")->insert([
    \"order_code\" => \"{$orderCode}\",
    \"type\" => \"HHHK\",
    \"order_category_id\" => {$categoryId},
    \"customer_id\" => {$customerId},
    \"cargo_name\" => \"Hàng không điểm đến\",
    \"cargo_type\" => \"GCR\",
    \"total_packages\" => 5,
    \"total_weight\" => 1.5,
    \"pickup_address\" => \"KCN Vsip, Bình Dương\",
    \"pickup_contact\" => \"Anh Kho Demo\",
    \"pickup_phone\" => \"0909999998\",
    \"planned_loading_at\" => \"{$now}\",
    \"vehicle_id\" => {$vehicleId},
    \"driver_id\" => {$ids['driver_id']},
    \"status\" => \"sent\",
    \"is_return_trip\" => false,
    \"created_by\" => {$ids['driver_id']},
    \"created_at\" => \"{$now}\",
    \"updated_at\" => \"{$now}\",
]);
echo DB::getPdo()->lastInsertId();
' 2>/dev/null"));

assertTrue($orderId > 0, "Đã tạo đơn không có điểm đến (ID: $orderId)");

// Kiểm tra không có delivery point
$dpCountOutput = shell_exec("php artisan tinker --execute '
echo DB::table(\"order_delivery_points\")->where(\"order_id\",{$orderId})->count();
' 2>/dev/null");
assertTrue((int) trim($dpCountOutput) === 0, 'Đơn không có delivery point nào (count=0)');

// ─── 2. Login ─────────────────────────────────────────────────────────
step(2, '🔐 Đăng nhập');

$loginResult = request('POST', "$baseUrl/api/driver/login", null, [
    'email' => $email,
    'password' => $password,
]);
$token = $loginResult['body']['token'];
ok('Token: '.substr($token, 0, 20).'...');

// ─── 3. Dọn dẹp + Vào ca ─────────────────────────────────────────────
step(3, '🧹 Dọn dẹp ca cũ & 🟢 Vào ca');

// Xoá tất cả ca cũ & reset xe (disable FK checks to handle all references)
$cleanup = shell_exec("php artisan tinker --execute '
\$driverId = {$ids['driver_id']};
\$vehicleId = {$vehicleId};
\$count = DB::table(\"driver_shifts\")->where(\"driver_id\",\$driverId)->count();
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
echo \"Deleted \$count shifts\";
' 2>&1");
info(trim($cleanup ?? 'no output'));

$shiftResult = request('POST', "$baseUrl/api/driver/shifts/start", $token, [
    'vehicle_id' => $vehicleId,
    'shift_type' => 'full',
    'start_time' => date('c'),
    'start_gps_lat' => '10.8554',
    'start_gps_lng' => '106.7913',
]);
$shiftId = $shiftResult['body']['shift']['id'];
ok("Shift ID: $shiftId");

// ─── 4. Checkpoint: started ──────────────────────────────────────────
step(4, '🚀 started');
request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'checkpoint_type' => 'started',
    'occurred_at' => date('c'),
    'gps_lat' => '10.8554',
    'gps_lng' => '106.7913',
]);
ok('Started');

// ─── 5. arrived_pickup ───────────────────────────────────────────────
step(5, '📍 arrived_pickup');
request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'checkpoint_type' => 'arrived_pickup',
    'km_reading' => 20010,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8554',
    'gps_lng' => '106.7913',
]);
ok('ArrivedPickup');

// ─── 6. left_pickup ──────────────────────────────────────────────────
step(6, '🚛 left_pickup → Delivering');
request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'checkpoint_type' => 'left_pickup',
    'km_reading' => 20015,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8188',
    'gps_lng' => '106.6580',
]);
ok('LeftPickup → Delivering');

// ─── 7. arrived_delivery KHÔNG có new_delivery_location_id ────────────
step(7, '⛔ arrived_delivery KHÔNG có new_delivery_location_id → kỳ vọng 422');

$result422 = request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'checkpoint_type' => 'arrived_delivery',
    'km_reading' => 20050,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8188',
    'gps_lng' => '106.6580',
], true); // allowError = true

assertTrue($result422['status'] === 422, "Nhận HTTP 422 (thực tế: {$result422['status']})");
assertTrue(
    str_contains($result422['body']['message'] ?? '', 'chọn điểm giao'),
    'Message chứa "chọn điểm giao": '.($result422['body']['message'] ?? 'N/A')
);

// ─── 8. arrived_delivery CÓ new_delivery_location_id ─────────────────
step(8, '✅ arrived_delivery CÓ new_delivery_location_id → kỳ vọng 200');

$arrivedResult = request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'checkpoint_type' => 'arrived_delivery',
    'new_delivery_location_id' => $deliveryLocationId,
    'km_reading' => 20050,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8188',
    'gps_lng' => '106.6580',
]);

assertTrue($arrivedResult['status'] === 200, "Nhận HTTP 200 (thực tế: {$arrivedResult['status']})");

// Kiểm tra delivery point đã được tạo tự động
$deliveryPointCreated = shell_exec("php artisan tinker --execute '
\$dp = DB::table(\"order_delivery_points\")->where(\"order_id\",{$orderId})->first();
echo json_encode(\$dp ? [\"id\"=>\$dp->id,\"location_id\"=>\$dp->location_id,\"sequence\"=>\$dp->sequence,\"status\"=>\$dp->status] : null);
' 2>/dev/null");

$dp = json_decode($deliveryPointCreated, true);
assertTrue($dp !== null, 'Delivery point đã được tạo trong DB');
assertTrue((int) ($dp['location_id'] ?? 0) === $deliveryLocationId, "location_id = $deliveryLocationId (thực tế: {$dp['location_id']})");
assertTrue((int) ($dp['sequence'] ?? 0) === 1, 'sequence = 1');
assertTrue(($dp['status'] ?? '') === 'arrived', "status = arrived (thực tế: {$dp['status']})");
info('Delivery point ID: '.$dp['id']);

// ─── 9. completed ────────────────────────────────────────────────────
step(9, '✅ completed');
$dpId = (int) $dp['id'];

$completedResult = request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'delivery_point_id' => $dpId,
    'checkpoint_type' => 'completed',
    'km_reading' => 20090,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8188',
    'gps_lng' => '106.6580',
]);
assertTrue($completedResult['status'] === 200, "completed HTTP 200 (thực tế: {$completedResult['status']})");

// Kiểm tra delivery point đã chuyển sang delivered
$dpStatus = shell_exec("php artisan tinker --execute '
echo DB::table(\"order_delivery_points\")->where(\"id\",{$dpId})->value(\"status\");
' 2>/dev/null");
assertTrue(trim($dpStatus) === 'delivered', "Delivery point status = delivered (thực tế: $dpStatus)");

// ─── 10. Kết thúc ca ─────────────────────────────────────────────────
step(10, '⏹️  Kết thúc ca');

$endResult = request('POST', "$baseUrl/api/driver/shifts/end", $token, [
    'end_km' => 20100,
    'end_time' => date('c'),
    'end_gps_lat' => '10.8188',
    'end_gps_lng' => '106.6580',
]);
$shift = $endResult['body']['shift'];

echo "\n";
echo "════════════════════════════════════════════════\n";
echo "  ✅ Hoàn tất!\n\n";
echo "  📊 Kết quả KM:\n";
echo "    total_km        = {$shift['total_km']} km\n";
echo "    total_km_loaded  = {$shift['total_km_loaded']} km\n";
echo "    total_km_empty   = {$shift['total_km_empty']} km\n";
echo "\n";
echo "  📄 Kết quả đơn:\n";
$orderDetail = request('GET', "$baseUrl/api/driver/orders/{$orderId}", $token);
echo "    #{$orderDetail['body']['data']['id']} {$orderDetail['body']['data']['order_code']}: {$orderDetail['body']['data']['status']}\n";
echo "════════════════════════════════════════════════\n";
