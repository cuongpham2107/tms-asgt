<?php

namespace App\Console\Commands;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\VehicleGpsPoint;
use App\Services\OsrmService;
use App\Services\TripKmCalculatorService;
use Illuminate\Console\Command;

class MockTripGpsTrack extends Command
{
    protected $signature = 'trip:mock-gps
                            {trip : ID hoặc mã chuyến (trip_code)}
                            {--speed=40 : Vận tốc trung bình (km/h)}
                            {--interval=30 : Khoảng cách giữa 2 điểm GPS (giây)}
                            {--sync-checkpoints=1 : Đồng bộ thời gian checkpoint theo vận tốc thực tế để tính chuẩn km có hàng/không hàng}
                            {--clean : Xóa các điểm GPS cũ của xe trong khoảng thời gian chuyến}';

    protected $description = 'Mô phỏng chuỗi toạ độ GPS bám đường thực tế (OSRM) cho một chuyến xe phục vụ test desk';

    public function handle(OsrmService $osrm, TripKmCalculatorService $calculator): int
    {
        $tripArg = $this->argument('trip');

        /** @var Trip|null $trip */
        $trip = is_numeric($tripArg)
            ? Trip::with([
                'vehicle',
                'driver',
                'driverAssignments',
                'checkpoints.deliveryPoint.location',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
            ])->find($tripArg)
            : Trip::with([
                'vehicle',
                'driver',
                'driverAssignments',
                'checkpoints.deliveryPoint.location',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
            ])->where('trip_code', $tripArg)->first();

        if (! $trip) {
            $this->error("Không tìm thấy chuyến đi: {$tripArg}");

            return self::FAILURE;
        }

        if (! $trip->vehicle_id) {
            $this->error("Chuyến đi {$trip->trip_code} chưa được gán xe (vehicle_id).");

            return self::FAILURE;
        }

        $speedKmh = (float) $this->option('speed');
        $intervalSeconds = (int) $this->option('interval');
        $syncCheckpoints = (bool) $this->option('sync-checkpoints');

        $this->info("Đang tạo toạ độ GPS mô phỏng cho chuyến {$trip->trip_code} (Xe: {$trip->vehicle->plate_number})...");

        // 1. Thu thập danh sách các checkpoint có toạ độ
        $checkpoints = $trip->checkpoints()
            ->with(['deliveryPoint.location'])
            ->orderBy('occurred_at')
            ->get();

        $orderedPoints = [];

        if ($checkpoints->isNotEmpty()) {
            foreach ($checkpoints as $cp) {
                $lat = $cp->gps_lat ? (float) $cp->gps_lat : (float) ($cp->deliveryPoint?->location?->latitude ?? 0);
                $lng = $cp->gps_lng ? (float) $cp->gps_lng : (float) ($cp->deliveryPoint?->location?->longitude ?? 0);

                if ($lat != 0.0 && $lng != 0.0) {
                    $orderedPoints[] = [
                        'checkpoint' => $cp,
                        'lat' => $lat,
                        'lng' => $lng,
                        'name' => $cp->checkpoint_type->getLabel().($cp->deliveryPoint?->location?->code ? ' ('.$cp->deliveryPoint->location->code.')' : ''),
                    ];
                }
            }
        }

        // Nếu waypoints < 2, bổ sung từ thông tin đơn hàng
        if (count($orderedPoints) < 2) {
            foreach ($trip->orders as $order) {
                $pLat = $order->pickupLocation?->lat ?? $order->pickupLocation?->latitude;
                $pLng = $order->pickupLocation?->lng ?? $order->pickupLocation?->longitude;
                if ($pLat && $pLng) {
                    $orderedPoints[] = [
                        'checkpoint' => null,
                        'lat' => (float) $pLat,
                        'lng' => (float) $pLng,
                        'name' => 'Lấy hàng: '.($order->pickupLocation->code ?? $order->pickupLocation->name),
                    ];
                }
                foreach ($order->deliveryPoints as $dp) {
                    $dLat = $dp->location?->lat ?? $dp->location?->latitude;
                    $dLng = $dp->location?->lng ?? $dp->location?->longitude;
                    if ($dLat && $dLng) {
                        $orderedPoints[] = [
                            'checkpoint' => null,
                            'lat' => (float) $dLat,
                            'lng' => (float) $dLng,
                            'name' => 'Giao hàng: '.($dp->location->code ?? $dp->location->name),
                        ];
                    }
                }
            }
        }

        if (count($orderedPoints) < 2) {
            $this->error('Chuyến không đủ toạ độ điểm dừng (cần tối thiểu 2 điểm). Vui lòng kiểm tra toạ độ mốc hành trình hoặc địa điểm lấy/giao hàng.');

            return self::FAILURE;
        }

        $this->line('Các điểm chặng xác định:');
        foreach ($orderedPoints as $idx => $op) {
            $this->line(sprintf('  [%d] %s (%.5f, %.5f)', $idx + 1, $op['name'], $op['lat'], $op['lng']));
        }

        $speedMps = ($speedKmh * 1000.0) / 3600.0;

        // 2. Tính toán lộ trình chi tiết từng chặng
        $legs = [];
        $totalEstimatedSeconds = 0;

        for ($i = 0; $i < count($orderedPoints) - 1; $i++) {
            $from = $orderedPoints[$i];
            $to = $orderedPoints[$i + 1];

            $directDist = $this->haversineMeters($from['lat'], $from['lng'], $to['lat'], $to['lng']);
            $coords = [];

            if ($directDist > 30) {
                $res = $osrm->getRoute($from['lat'], $from['lng'], $to['lat'], $to['lng']);
                if (! empty($res['success']) && ! empty($res['data']['geometry']['coordinates'])) {
                    foreach ($res['data']['geometry']['coordinates'] as $c) {
                        $coords[] = ['lat' => (float) $c[1], 'lng' => (float) $c[0]];
                    }
                }
            } else {
                // Di chuyển vi mô trong bãi / kho (docking, bốc dỡ) khoảng 150m - 250m (0.2km)
                $baseLat = $from['lat'];
                $baseLng = $from['lng'];
                $coords = [
                    ['lat' => $baseLat, 'lng' => $baseLng],
                    ['lat' => $baseLat + 0.0004, 'lng' => $baseLng + 0.0003],
                    ['lat' => $baseLat + 0.0007, 'lng' => $baseLng + 0.0006],
                    ['lat' => $baseLat + 0.0004, 'lng' => $baseLng + 0.0009],
                    ['lat' => $baseLat, 'lng' => $baseLng + 0.0011],
                ];
            }

            if (empty($coords)) {
                $coords = [
                    ['lat' => $from['lat'], 'lng' => $from['lng']],
                    ['lat' => $to['lat'], 'lng' => $to['lng']],
                ];
            }

            // Sample path
            $sampled = [$coords[0]];
            for ($k = 1; $k < count($coords); $k++) {
                $prev = end($sampled);
                $d = $this->haversineMeters($prev['lat'], $prev['lng'], $coords[$k]['lat'], $coords[$k]['lng']);
                if ($d >= 20 || $k === count($coords) - 1) {
                    $sampled[] = $coords[$k];
                }
            }

            $legMeters = 0.0;
            for ($k = 1; $k < count($sampled); $k++) {
                $legMeters += $this->haversineMeters(
                    $sampled[$k - 1]['lat'],
                    $sampled[$k - 1]['lng'],
                    $sampled[$k]['lat'],
                    $sampled[$k]['lng']
                );
            }

            $legDurationSeconds = $legMeters > 50
                ? max(60, (int) round($legMeters / $speedMps))
                : 30; // Chờ bốc dỡ nếu ở cùng địa điểm

            $totalEstimatedSeconds += $legDurationSeconds;

            $legs[] = [
                'from_idx' => $i,
                'to_idx' => $i + 1,
                'path' => $sampled,
                'meters' => $legMeters,
                'duration_seconds' => $legDurationSeconds,
            ];
        }

        // Thời điểm bắt đầu
        $startTime = $trip->started_at ?? now()->subSeconds($totalEstimatedSeconds + 120);
        $currentTime = $startTime->copy();

        if ($this->option('clean')) {
            $endTime = $startTime->copy()->addSeconds($totalEstimatedSeconds + 300);
            $deleted = VehicleGpsPoint::where('vehicle_id', $trip->vehicle_id)
                ->whereBetween('recorded_at', [$startTime->copy()->subMinutes(10), $endTime])
                ->delete();
            $this->info("Đã xóa {$deleted} điểm GPS cũ.");
        }

        $allPoints = [];
        $seq = 1;

        // Điểm đầu tiên
        if ($syncCheckpoints && ! empty($orderedPoints[0]['checkpoint'])) {
            $orderedPoints[0]['checkpoint']->occurred_at = $currentTime;
            $orderedPoints[0]['checkpoint']->save();
        }

        foreach ($legs as $leg) {
            $sampled = $leg['path'];
            $legDuration = $leg['duration_seconds'];
            $legMeters = $leg['meters'];
            $stepSeconds = count($sampled) > 1
                ? max(2, (int) round($legDuration / (count($sampled) - 1)))
                : $legDuration;

            $legStartTime = $currentTime->copy();

            for ($p = 0; $p < count($sampled); $p++) {
                $pt = $sampled[$p];
                $pointTime = $legStartTime->copy()->addSeconds($p * $stepSeconds);

                if ($p > 0) {
                    $prev = $sampled[$p - 1];
                    $heading = $this->bearing($prev['lat'], $prev['lng'], $pt['lat'], $pt['lng']);
                } else {
                    $heading = 0.0;
                }

                $currentSpeed = $legMeters > 50 ? ($speedKmh + (mt_rand(-30, 30) / 10.0)) : 0.0;

                $allPoints[] = [
                    'vehicle_id' => $trip->vehicle_id,
                    'driver_id' => $trip->driver_id,
                    'shift_id' => null,
                    'device_id' => 'mock_cli',
                    'seq' => $seq++,
                    'recorded_at' => $pointTime->format('Y-m-d H:i:s'),
                    'lat' => $pt['lat'],
                    'lng' => $pt['lng'],
                    'speed' => max(0.0, round($currentSpeed, 1)),
                    'heading' => round($heading, 1),
                    'accuracy' => round(5.0 + (mt_rand(0, 30) / 10.0), 1),
                    'mocked' => false,
                    'source' => VehicleGpsPoint::SOURCE_PHONE,
                    'created_at' => now(),
                ];
            }

            $currentTime = $legStartTime->copy()->addSeconds($legDuration);

            // Cập nhật timestamp của checkpoint đích
            $toCp = $orderedPoints[$leg['to_idx']]['checkpoint'] ?? null;
            if ($syncCheckpoints && $toCp) {
                $toCp->occurred_at = $currentTime;
                $toCp->save();
            }
        }

        // Chèn vào vehicle_gps_points
        foreach (array_chunk($allPoints, 200) as $chunk) {
            VehicleGpsPoint::insert($chunk);
        }

        $endTime = $currentTime->copy();

        $this->info(sprintf('Đã chèn %d điểm GPS (từ %s đến %s, tổng độ dài ~%.1f km)',
            count($allPoints),
            $startTime->format('H:i:s d/m/Y'),
            $endTime->format('H:i:s d/m/Y'),
            array_sum(array_column($legs, 'meters')) / 1000.0
        ));

        // Cập nhật trip & driver assignments
        $trip->started_at = $startTime;
        if ($trip->status === TripStatus::Completed) {
            $trip->completed_at = $endTime;
        }

        if ($trip->driverAssignments->isNotEmpty()) {
            $firstDa = $trip->driverAssignments->first();
            $firstDa->started_at = $startTime;
            $firstDa->save();

            $lastDa = $trip->driverAssignments->last();
            if ($lastDa && ($trip->status === TripStatus::Completed || $lastDa->ended_at !== null)) {
                $lastDa->ended_at = $endTime;
                $lastDa->save();
            }
        }

        $trip->km_calculated_at = null;
        $trip->save();

        // 5. Tính toán lại km
        $this->line('Đang chạy lại dịch vụ tính toán km...');
        $trip->refresh();
        $calculator->calculate($trip);
        $trip->refresh();

        $this->table(
            ['Chỉ số', 'Giá trị'],
            [
                ['Mã chuyến', $trip->trip_code],
                ['Tổng km', number_format((float) $trip->total_km, 1, ',', '.').' km'],
                ['Km có hàng', number_format((float) $trip->total_km_loaded, 1, ',', '.').' km'],
                ['Km không hàng', number_format((float) $trip->total_km_empty, 1, ',', '.').' km'],
                ['Nguồn tính', $trip->km_source ?? 'gps'],
                ['Thời gian bắt đầu', $trip->started_at?->format('H:i:s d/m/Y') ?? '—'],
                ['Thời gian kết thúc', $trip->completed_at?->format('H:i:s d/m/Y') ?? '—'],
            ]
        );

        return self::SUCCESS;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earthRadius * asin(sqrt($a));
    }

    private function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $y = sin(deg2rad($lng2 - $lng1)) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1));

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }
}
