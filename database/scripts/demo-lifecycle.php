#!/usr/bin/env php
<?php

/**
 * Demo: Full Driver Lifecycle via API
 *
 * Mô phỏng luồng làm việc thực tế của tài xế:
 *   1. Vào ca (start shift)
 *   2. Nhận đơn → started → arrived_pickup → left_pickup → arrived_delivery → completed
 *   3. Kết thúc ca (end shift)
 *
 * Công thức km mới (theo plan 2026-06-10):
 *   - started.km tự động lấy từ vehicle.current_mileage (ko nhập)
 *   - Tài xế chỉ nhập km tại arrived_pickup và completed
 *   - total_km_loaded = completed.km - arrived_pickup.km
 *
 * Usage:
 *   1. php artisan serve (hoặc đảm bảo app đang chạy)
 *   2. php database/scripts/demo-lifecycle.php
 *
 * Yêu cầu: PHP 8.1+, curl extension, đã chạy migrate + seeder
 */

declare(strict_types=1);

// ─── Cấu hình ─────────────────────────────────────────────────────────

$baseUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');
$email = 'driver.demo@example.com';
$password = 'password';

// ─── Helpers ──────────────────────────────────────────────────────────

function request(string $method, string $url, ?string $token = null, ?array $data = null): array
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

    if (! $isSuccess) {
        echo "  ⛔ HTTP $status: ".($result['message'] ?? $body)."\n";
        exit(1);
    }

    return $result ?? [];
}

function step(int $num, string $label): void
{
    echo "\n─── [$num/8] $label ───\n";
}

function info(string $msg): void
{
    echo "  ℹ️  $msg\n";
}

function ok(string $msg): void
{
    echo "  ✅ $msg\n";
}

function jsonPretty(array $data, ?string $key = null): void
{
    $display = $key !== null ? ($data[$key] ?? $data) : $data;
    echo json_encode($display, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
}

// ─── 0. Seed + lấy ID ────────────────────────────────────────────────
echo ">>> Đang seed dữ liệu demo...\n";
passthru('php artisan db:seed --class=FullOrderLifecycleSeeder --no-interaction 2>/dev/null', $exitCode);

if ($exitCode !== 0) {
    echo "⚠️  Seeder báo lỗi, tiếp tục với dữ liệu có sẵn...\n";
}

echo ">>> Đang truy vấn entity IDs...\n";
$idsOutput = shell_exec("php artisan tinker --execute '
\$d = DB::table(\"users\")->where(\"email\",\"driver.demo@example.com\")->value(\"id\");
\$v = DB::table(\"vehicles\")->where(\"plate_number\",\"99X-99999\")->value(\"id\");
\$o = DB::table(\"orders\")->where(\"driver_id\",\$d)->orderByDesc(\"id\")->value(\"id\");
\$p = DB::table(\"order_delivery_points\")->where(\"order_id\",\$o)->orderBy(\"sequence\")->value(\"id\");
echo json_encode([\"driver_id\"=>\$d,\"vehicle_id\"=>\$v,\"order_id\"=>\$o,\"dp_id\"=>\$p]);
' 2>/dev/null");

$ids = json_decode($idsOutput, true);
if (! $ids || empty($ids['driver_id'])) {
    echo "⛔ Không tìm thấy dữ liệu demo. Chạy migrate + seed trước.\n";
    exit(1);
}

$orderId = (int) $ids['order_id'];
$deliveryPointId = (int) $ids['dp_id'];
$vehicleId = (int) $ids['vehicle_id'];

info("Driver ID: {$ids['driver_id']}, Vehicle ID: $vehicleId, Order ID: $orderId, Delivery Point ID: $deliveryPointId");

// Dọn dẹp dữ liệu ca cũ (nếu có) trước khi chạy demo mới
echo ">>> Dọn dẹp ca cũ & reset đơn...\n";
shell_exec("php artisan tinker --execute '
\$driverId = {$ids['driver_id']};
\$orderId = {$orderId};

// Reset order v? sent
DB::table(\"orders\")->where(\"id\",\$orderId)->update([\"status\"=>\"sent\",\"shift_id\"=>null]);

// Xóa checkpoint c?
DB::table(\"trip_checkpoints\")->where(\"order_id\",\$orderId)->delete();

// Xóa shift vehicle segments + shift c?a driver hôm nay
\$today = now()->toDateString();
\$shiftIds = DB::table(\"driver_shifts\")
    ->where(\"driver_id\",\$driverId)
    ->whereDate(\"start_time\",\$today)
    ->pluck(\"id\");
foreach (\$shiftIds as \$sid) {
    DB::table(\"shift_vehicles\")->where(\"shift_id\",\$sid)->delete();
}
DB::table(\"driver_shifts\")->whereIn(\"id\",\$shiftIds)->delete();

// Reset vehicle v? tr?ng thái ban d?u
DB::table(\"vehicles\")->where(\"id\",{$vehicleId})->update([
    \"current_driver_id\"=>null,
    \"current_mileage\"=>10000,
]);

// Reset delivery point v? pending
DB::table(\"order_delivery_points\")->where(\"order_id\",\$orderId)->update([\"status\"=>\"pending\"]);

echo \"OK\";
' 2>/dev/null");
info('Đã dọn dẹp dữ liệu ca cũ & reset đơn về sent');

$totalSteps = 8;

// ─── 1. Login ─────────────────────────────────────────────────────────
step(1, '🔐 Đăng nhập');

$loginResult = request('POST', "$baseUrl/api/driver/login", null, [
    'email' => $email,
    'password' => $password,
]);
$token = $loginResult['token'];
ok('Token: '.substr($token, 0, 20).'...');

// ─── 2. Kiểm tra trạng thái ca, xe available ─────────────────────────
step(2, '🚛 Kiểm tra ca hiện tại & xe available');

$activeShift = request('GET', "$baseUrl/api/driver/shifts/active", $token);
if ($activeShift['active_shift'] !== null) {
    info("Đã có ca đang hoạt động (ID: {$activeShift['active_shift']['id']})");
} else {
    info('Chưa có ca nào, km gần nhất: '.($activeShift['last_km'] ?? 'N/A'));
}

$availableVehicles = request('GET', "$baseUrl/api/driver/vehicles/available", $token);
info('Số xe available: '.count($availableVehicles['data'] ?? []));

// ─── 3. Vào ca (start shift) ─────────────────────────────────────────
step(3, '🟢 Vào ca');

// Theo công thức mới: ko cần vehicle_id, ko cần start_km
// start_km sẽ tự lấy từ vehicle.current_mileage khi tạo started checkpoint
$shiftResult = request('POST', "$baseUrl/api/driver/shifts/start", $token, [
    'vehicle_id' => $vehicleId,
    'shift_type' => 'full',
    'start_time' => date('c'),
    'start_gps_lat' => '10.8554',
    'start_gps_lng' => '106.7913',
]);
$shiftId = $shiftResult['shift']['id'];
ok("Shift ID: $shiftId");
jsonPretty($shiftResult, 'shift');

// ─── 4. Xem danh sách đơn ────────────────────────────────────────────
step(4, '📋 Danh sách đơn của tôi');

$orders = request('GET', "$baseUrl/api/driver/orders", $token);
info('Tổng số đơn: '.count($orders['data'] ?? 0));
foreach ($orders['data'] ?? [] as $o) {
    echo "    - #{$o['id']} {$o['order_code']}: {$o['status']}\n";
}

// Đặt km cho xe như trong thực tế (xe đã chạy trước đó)
// Cập nhật current_mileage của xe để started checkpoint tự lấy
shell_exec("php artisan tinker --execute '
DB::table(\"vehicles\")->where(\"id\",$vehicleId)->update([\"current_mileage\"=>10000]);
echo \"OK\";
' 2>/dev/null");
info('Đã đặt vehicle.current_mileage = 10000');

// ─── 5. Checkpoint: started ──────────────────────────────────────────
step(5, '🚀 started (ko nhập km, tự lấy từ xe = 10000)');

// Theo công thức mới: ko nhập km, server tự lấy từ vehicle.current_mileage
$started = request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'checkpoint_type' => 'started',
    'occurred_at' => date('c'),
    'gps_lat' => '10.8554',
    'gps_lng' => '106.7913',
]);
ok('Checkpoint ID: '.($started['checkpoint']['id'] ?? '?'));

// ─── 6. arrived_pickup ───────────────────────────────────────────────
step(6, '📍 arrived_pickup (km=10010)');

request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'delivery_point_id' => $deliveryPointId,
    'checkpoint_type' => 'arrived_pickup',
    'km_reading' => 10010,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8554',
    'gps_lng' => '106.7913',
]);
ok('ArrivedPickup');

// left_pickup
request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'checkpoint_type' => 'left_pickup',
    'km_reading' => 10015,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8188',
    'gps_lng' => '106.6580',
]);
ok('LeftPickup → Delivering');

// arrived_delivery
request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'delivery_point_id' => $deliveryPointId,
    'checkpoint_type' => 'arrived_delivery',
    'km_reading' => 10080,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8188',
    'gps_lng' => '106.6580',
]);
ok('ArrivedDelivery');

// ─── 7. completed ────────────────────────────────────────────────────
step(7, '✅ completed (km=10090)');

request('POST', "$baseUrl/api/driver/checkpoints", $token, [
    'order_id' => $orderId,
    'shift_id' => $shiftId,
    'delivery_point_id' => $deliveryPointId,
    'checkpoint_type' => 'completed',
    'km_reading' => 10090,
    'occurred_at' => date('c'),
    'gps_lat' => '10.8188',
    'gps_lng' => '106.6580',
]);
ok('Completed');

// ─── 8. Kết thúc ca ──────────────────────────────────────────────────
step(8, '⏹️  Kết thúc ca (end_km=10100)');

$endResult = request('POST', "$baseUrl/api/driver/shifts/end", $token, [
    'end_km' => 10100,
    'end_time' => date('c'),
    'end_gps_lat' => '10.8188',
    'end_gps_lng' => '106.6580',
]);

$shift = $endResult['shift'];
echo "\n";
echo "════════════════════════════════════════════════\n";
echo "  ✅ Kết thúc ca thành công!\n\n";
echo "  📊 Kết quả KM:\n";
echo "    total_km       = {$shift['total_km']} km\n";
echo "    total_km_loaded = {$shift['total_km_loaded']} km\n";
echo "    total_km_empty  = {$shift['total_km_empty']} km\n";
echo "\n";
echo "  📄 Trạng thái đơn:\n";

// Kiểm tra lại đơn sau khi hoàn tất
$orderDetail = request('GET', "$baseUrl/api/driver/orders/{$orderId}", $token);
echo "    #{$orderDetail['data']['id']} {$orderDetail['data']['order_code']}: {$orderDetail['data']['status']}\n";
echo "════════════════════════════════════════════════\n";

// Dự tính kỳ vọng
$expectedTotalKm = 10100 - 10000; // 100
$expectedLoaded = 10090 - 10010; // 80
$expectedEmpty = $expectedTotalKm - $expectedLoaded; // 20

echo "\n";
echo "🔍 Kiểm tra kết quả:\n";
echo "  total_km:       {$shift['total_km']} (kỳ vọng: $expectedTotalKm) ".((float) $shift['total_km'] === (float) $expectedTotalKm ? '✅' : '❌')."\n";
echo "  total_km_loaded: {$shift['total_km_loaded']} (kỳ vọng: $expectedLoaded) ".((float) $shift['total_km_loaded'] === (float) $expectedLoaded ? '✅' : '❌')."\n";
echo "  total_km_empty:  {$shift['total_km_empty']} (kỳ vọng: $expectedEmpty) ".((float) $shift['total_km_empty'] === (float) $expectedEmpty ? '✅' : '❌')."\n";
