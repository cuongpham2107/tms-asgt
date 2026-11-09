<?php

namespace App\Console\Commands;

use App\Enums\CheckpointType;
use App\Enums\GeoCheckStatus;
use App\Models\Location;
use App\Models\TripCheckpoint;
use App\Services\Trip\TripCheckpointOsmValidationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReevaluateCheckpointsCommand extends Command
{
    protected $signature = 'checkpoints:re-evaluate 
                            {--days=7 : Số ngày gần nhất cần tính toán lại}
                            {--dry-run : Chỉ thống kê so sánh, không ghi vào DB}';

    protected $description = 'Chấm lại kết quả đối chiếu Geofence (Lớp A & Lớp B) cho các mốc hành trình theo dữ liệu kho và thuật toán mới';

    public function handle(TripCheckpointOsmValidationService $geo): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $fromDate = now()->subDays($days)->startOfDay();

        $this->info("Đang tải dữ liệu mốc từ {$fromDate->toDateTimeString()} ({$days} ngày qua)...");

        $locationsById = Location::query()->get()->keyBy('id');
        $ordersById = DB::table('orders')->select('id', 'pickup_location_id')->get()->keyBy('id');
        $dpById = DB::table('order_delivery_points')->select('id', 'order_id', 'location_id')->get()->keyBy('id');

        $baseRadiusM = (int) config('checkpoint_geofence.radius_m', 3000);
        $movingSpeedThreshold = (float) config('checkpoint_geofence.moving_speed_threshold_kmh', 15);
        $maxVehicleRadiusM = (int) config('checkpoint_geofence.max_vehicle_radius_m', 1500);
        $arriveWindow = (int) config('checkpoint_geofence.track_window_arrive_seconds', 900);
        $leaveWindow = (int) config('checkpoint_geofence.track_window_leave_seconds', 1800);

        $checkpoints = TripCheckpoint::where('occurred_at', '>=', $fromDate)
            ->whereNotNull('vehicle_geo_status')
            ->orderBy('occurred_at')
            ->get();

        if ($checkpoints->isEmpty()) {
            $this->warn('Không tìm thấy mốc nào cần tính toán lại.');

            return self::SUCCESS;
        }

        $before = ['vehicle' => [], 'location' => []];
        $after = ['vehicle' => [], 'location' => []];
        $updates = [];

        foreach ($checkpoints as $cp) {
            $occurredAt = Carbon::parse($cp->occurred_at);
            $type = $cp->checkpoint_type instanceof CheckpointType
                ? $cp->checkpoint_type
                : CheckpointType::tryFrom((string) $cp->checkpoint_type);

            $vOld = $cp->vehicle_geo_status instanceof GeoCheckStatus ? $cp->vehicle_geo_status->value : (string) $cp->vehicle_geo_status;
            $lOld = $cp->location_geo_status instanceof GeoCheckStatus ? $cp->location_geo_status->value : (string) $cp->location_geo_status;

            $before['vehicle'][$vOld] = ($before['vehicle'][$vOld] ?? 0) + 1;
            $before['location'][$lOld] = ($before['location'][$lOld] ?? 0) + 1;

            if ($type === null || $type === CheckpointType::DriverSwap) {
                $vNew = GeoCheckStatus::Skipped->value;
                $vDistNew = null;
                $lNew = GeoCheckStatus::Skipped->value;
                $lDistNew = null;
            } else {
                $eupLat = $cp->eup_lat !== null ? (float) $cp->eup_lat : null;
                $eupLng = $cp->eup_lng !== null ? (float) $cp->eup_lng : null;
                $eupRec = $cp->eup_recorded_at ? Carbon::parse($cp->eup_recorded_at) : null;
                $eupSpd = $cp->eup_speed !== null ? (float) $cp->eup_speed : null;

                $hasPhone = is_numeric($cp->gps_lat) && is_numeric($cp->gps_lng) && abs((float) $cp->gps_lat) >= 0.001 && abs((float) $cp->gps_lng) >= 0.001;
                $hasEup = is_numeric($eupLat) && is_numeric($eupLng) && abs((float) $eupLat) >= 0.001 && abs((float) $eupLng) >= 0.001;

                // Lớp A: Tài xế ↔ Xe
                $vRadius = $baseRadiusM;
                if ($hasEup && $eupSpd !== null && $eupSpd > $movingSpeedThreshold) {
                    $ageSec = $eupRec ? max(60, (int) abs($occurredAt->diffInSeconds($eupRec))) : 60;
                    $compM = (int) round(($eupSpd / 3.6) * $ageSec);
                    $vRadius = min($maxVehicleRadiusM, $baseRadiusM + $compM);
                }

                if (! $hasPhone || ! $hasEup) {
                    $vNew = GeoCheckStatus::Unverified->value;
                    $vDistNew = null;
                } else {
                    $vDistNew = (int) round($geo->calculateHaversineKm((float) $cp->gps_lat, (float) $cp->gps_lng, $eupLat, $eupLng) * 1000);
                    $vNew = $vDistNew <= $vRadius ? GeoCheckStatus::Valid->value : GeoCheckStatus::OutOfRange->value;
                }

                // Lớp B: Xe ↔ Điểm
                $targetLoc = null;
                if (in_array($type, [CheckpointType::ArrivedPickup, CheckpointType::LeftPickup], true)) {
                    $ord = $ordersById[$cp->order_id] ?? null;
                    if ($ord && $ord->pickup_location_id) {
                        $targetLoc = $locationsById[$ord->pickup_location_id] ?? null;
                    }
                } elseif (in_array($type, [CheckpointType::ArrivedDelivery, CheckpointType::Completed], true)) {
                    if ($cp->delivery_point_id && isset($dpById[$cp->delivery_point_id])) {
                        $locId = $dpById[$cp->delivery_point_id]->location_id;
                        $targetLoc = $locationsById[$locId] ?? null;
                    }
                }

                $hasLocCoord = $targetLoc && is_numeric($targetLoc->lat) && is_numeric($targetLoc->lng) && abs((float) $targetLoc->lat) >= 0.001 && abs((float) $targetLoc->lng) >= 0.001;
                $lRadius = ($targetLoc && is_numeric($targetLoc->geofence_radius_m) && (int) $targetLoc->geofence_radius_m > 0)
                    ? (int) $targetLoc->geofence_radius_m
                    : $baseRadiusM;

                if (! $hasLocCoord) {
                    $lNew = GeoCheckStatus::Skipped->value;
                    $lDistNew = null;
                } else {
                    $locLat = (float) $targetLoc->lat;
                    $locLng = (float) $targetLoc->lng;
                    $windowSec = in_array($type, [CheckpointType::LeftPickup, CheckpointType::Completed], true) ? $leaveWindow : $arriveWindow;

                    $trackPoints = collect();
                    if ($cp->vehicle_id) {
                        $trackPoints = DB::table('vehicle_gps_logs')
                            ->where('vehicle_id', $cp->vehicle_id)
                            ->whereBetween('recorded_at', [$occurredAt->copy()->subSeconds($windowSec), $occurredAt])
                            ->select('lat', 'lng')
                            ->get();
                    }

                    $candidates = [];
                    if ($trackPoints->isNotEmpty()) {
                        $candidates[] = $trackPoints->map(fn ($pt) => (int) round($geo->calculateHaversineKm((float) $pt->lat, (float) $pt->lng, $locLat, $locLng) * 1000))->min();
                    }
                    if ($hasEup) {
                        $candidates[] = (int) round($geo->calculateHaversineKm($eupLat, $eupLng, $locLat, $locLng) * 1000);
                    } elseif ($hasPhone) {
                        $candidates[] = (int) round($geo->calculateHaversineKm((float) $cp->gps_lat, (float) $cp->gps_lng, $locLat, $locLng) * 1000);
                    }

                    // Nếu mốc cũ diễn ra trước ngày lưu trữ logs (logs tự xoá sau 3 ngày), giữ khoảng cách mốc đã tìm thấy trước đó nếu nhỏ hơn
                    if ($trackPoints->isEmpty() && $cp->location_distance_m !== null) {
                        $candidates[] = (int) $cp->location_distance_m;
                    }

                    if (empty($candidates)) {
                        $lNew = GeoCheckStatus::Unverified->value;
                        $lDistNew = null;
                    } else {
                        $lDistNew = min($candidates);
                        $lNew = $lDistNew <= $lRadius ? GeoCheckStatus::Valid->value : GeoCheckStatus::OutOfRange->value;
                    }
                }
            }

            $after['vehicle'][$vNew] = ($after['vehicle'][$vNew] ?? 0) + 1;
            $after['location'][$lNew] = ($after['location'][$lNew] ?? 0) + 1;

            if ($vNew !== $vOld || $lNew !== $lOld || $vDistNew !== $cp->vehicle_distance_m || $lDistNew !== $cp->location_distance_m) {
                $updates[] = [
                    'id' => $cp->id,
                    'vehicle_geo_status' => $vNew,
                    'vehicle_distance_m' => $vDistNew,
                    'location_geo_status' => $lNew,
                    'location_distance_m' => $lDistNew,
                ];
            }
        }

        $bVChecked = ($before['vehicle']['valid'] ?? 0) + ($before['vehicle']['out_of_range'] ?? 0);
        $aVChecked = ($after['vehicle']['valid'] ?? 0) + ($after['vehicle']['out_of_range'] ?? 0);
        $bLChecked = ($before['location']['valid'] ?? 0) + ($before['location']['out_of_range'] ?? 0);
        $aLChecked = ($after['location']['valid'] ?? 0) + ($after['location']['out_of_range'] ?? 0);

        $this->table(
            ['Lớp đối chiếu', 'Trước (Hợp lệ)', 'Sau (Hợp lệ)', 'Trước (Ngoài bán kính)', 'Sau (Ngoài bán kính)', 'Chưa xác minh'],
            [
                [
                    'Lớp A (Tài xế ↔ Xe)',
                    sprintf('%d (%4.1f%%)', $before['vehicle']['valid'] ?? 0, $bVChecked ? ($before['vehicle']['valid'] ?? 0) * 100 / $bVChecked : 0),
                    sprintf('%d (%4.1f%%)', $after['vehicle']['valid'] ?? 0, $aVChecked ? ($after['vehicle']['valid'] ?? 0) * 100 / $aVChecked : 0),
                    (string) ($before['vehicle']['out_of_range'] ?? 0),
                    (string) ($after['vehicle']['out_of_range'] ?? 0),
                    sprintf('%d -> %d', $before['vehicle']['unverified'] ?? 0, $after['vehicle']['unverified'] ?? 0),
                ],
                [
                    'Lớp B (Xe ↔ Điểm)',
                    sprintf('%d (%4.1f%%)', $before['location']['valid'] ?? 0, $bLChecked ? ($before['location']['valid'] ?? 0) * 100 / $bLChecked : 0),
                    sprintf('%d (%4.1f%%)', $after['location']['valid'] ?? 0, $aLChecked ? ($after['location']['valid'] ?? 0) * 100 / $aLChecked : 0),
                    (string) ($before['location']['out_of_range'] ?? 0),
                    (string) ($after['location']['out_of_range'] ?? 0),
                    sprintf('%d -> %d', $before['location']['unverified'] ?? 0, $after['location']['unverified'] ?? 0),
                ],
            ]
        );

        $this->info('Số bản ghi có kết quả thay đổi: '.count($updates).' / '.$checkpoints->count());

        if ($dryRun) {
            $this->warn('Chế độ --dry-run: Chưa cập nhật bản ghi nào vào DB.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($updates) {
            foreach ($updates as $upd) {
                DB::table('trip_checkpoints')->where('id', $upd['id'])->update([
                    'vehicle_geo_status' => $upd['vehicle_geo_status'],
                    'vehicle_distance_m' => $upd['vehicle_distance_m'],
                    'location_geo_status' => $upd['location_geo_status'],
                    'location_distance_m' => $upd['location_distance_m'],
                ]);
            }
        });

        $this->info('Đã cập nhật thành công '.count($updates).' mốc vào cơ sở dữ liệu!');

        return self::SUCCESS;
    }
}
