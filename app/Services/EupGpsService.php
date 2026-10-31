<?php

namespace App\Services;

use App\Enums\EupCarStatus;
use App\Events\VehiclesSynced;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Services\Trip\TripCheckpointOsmValidationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EupGpsService
{
    /**
     * Xe di chuyển ít hơn khoảng này (và không đổi trạng thái) thì không ghi thêm điểm lộ trình.
     */
    private const MIN_MOVE_METERS = 30;

    private ?TripCheckpointOsmValidationService $geo = null;

    private string $baseUrl;

    private string $endpoint;

    private ?string $account;

    private ?string $password;

    private ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.eup.base_url', 'http://api.eup.net.vn:8000');
        $this->endpoint = config('services.eup.endpoint', '/ctyasg/realtimeAll');
        $this->account = config('services.eup.account');
        $this->password = config('services.eup.password');
        $this->apiKey = config('services.eup.api_key');
    }

    public function sync(int $timeout = 10): array
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($this->endpoint, '/');

        try {
            $body = [];

            if (! empty($this->account)) {
                $body['account'] = $this->account;
            }

            if (! empty($this->password)) {
                $body['password'] = $this->password;
            }

            $request = Http::timeout($timeout)->asForm();

            if ($this->apiKey !== null) {
                $request->withHeader('X-Eupfin-Api-Key', $this->apiKey);
            }

            $response = $request->post($url, $body);

            if (! $response->successful()) {
                Log::warning('EUP API request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['success' => false, 'message' => 'API trả về mã lỗi: '.$response->status()];
            }

            $data = $response->json();

            if (empty($data['result'])) {
                return ['success' => false, 'message' => 'API không trả về dữ liệu xe'];
            }

            $updated = 0;
            $errors = [];
            $lastItem = null;
            $vehicles = Vehicle::query()
                ->whereIn('plate_number', array_filter(array_column($data['result'], 'VehicleNo')))
                ->get()
                ->keyBy('plate_number');
            $lastLogs = VehicleGpsLog::query()
                ->whereIn('id', VehicleGpsLog::query()
                    ->whereIn('vehicle_id', $vehicles->pluck('id'))
                    ->groupBy('vehicle_id')
                    ->selectRaw('MAX(id)'))
                ->get()
                ->keyBy('vehicle_id');
            $logs = [];

            DB::transaction(function () use ($data, $vehicles, $lastLogs, &$logs, &$updated, &$errors, &$lastItem): void {
                foreach ($data['result'] as $item) {
                    $lastItem = $item;
                    $plateNumber = $item['VehicleNo'] ?? null;

                    if (empty($plateNumber)) {
                        continue;
                    }

                    $vehicle = $vehicles->get($plateNumber);

                    if ($vehicle === null) {
                        $errors[] = "Không tìm thấy xe {$plateNumber} trong hệ thống";

                        continue;
                    }

                    $updateData = $this->mapVehicleAttributes($item);
                    $updateData['idle_engine_since'] = $this->idleEngineSince($vehicle, $updateData);
                    $vehicle->update($updateData);
                    $updated++;

                    $log = $this->toGpsLog($vehicle, $updateData, $lastLogs->get($vehicle->id));
                    if ($log !== null) {
                        $logs[] = $log;
                    }
                }

                foreach (array_chunk($logs, 200) as $chunk) {
                    VehicleGpsLog::insert($chunk);
                }
            });

            if ($updated > 0) {
                // Reverb chưa chạy / lỗi kết nối thì bỏ qua — các màn hình vẫn tự tải lại định kỳ.
                rescue(fn () => VehiclesSynced::dispatch($updated), report: false);
            }

            $message = "Đã cập nhật vị trí cho {$updated} xe";

            if (count($errors) > 0) {
                $message .= ', '.count($errors).' xe không tìm thấy';
            }

            return [
                'success' => true,
                'message' => $message,
                'updated' => $updated,
                'errors' => $errors,
                'speed' => (float) ($lastItem['Speed'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::error('EUP API sync exception', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Lỗi kết nối đến API GPS: '.$e->getMessage()];
        }
    }

    /**
     * Chuyển một bản ghi EUP thành dữ liệu cập nhật vị trí hiện tại của xe.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapVehicleAttributes(array $item): array
    {
        $updateData = [];

        // Toạ độ 0/rỗng (thường gặp khi mất tín hiệu) không ghi đè vị trí đúng gần nhất của xe
        if (is_numeric($item['Latitude'] ?? null) && is_numeric($item['Longitude'] ?? null)
            && abs((float) $item['Latitude']) > 0.001 && abs((float) $item['Longitude']) > 0.001) {
            $updateData['gps_lat'] = (float) $item['Latitude'];
            $updateData['gps_lng'] = (float) $item['Longitude'];
        }

        if (isset($item['Speed'])) {
            $updateData['gps_speed'] = (float) $item['Speed'];
        }

        if (isset($item['CarStatus'])) {
            $updateData['car_status'] = (int) $item['CarStatus'];
        }

        if (isset($item['Direction'])) {
            $updateData['gps_direction'] = (int) $item['Direction'];
        }

        if (! empty($item['Address'])) {
            $updateData['gps_address'] = $item['Address'];
        }

        try {
            $updateData['last_gps_update'] = ! empty($item['OperateDate']) ? Carbon::parse($item['OperateDate']) : Carbon::now();
        } catch (\Throwable) {
            $updateData['last_gps_update'] = Carbon::now();
        }

        return $updateData;
    }

    /**
     * Điểm lộ trình cần lưu, null nếu thiếu toạ độ, thiết bị chưa ghi nhận điểm mới,
     * hoặc xe đứng yên (di chuyển dưới MIN_MOVE_METERS) mà trạng thái không đổi — tránh rác dữ liệu.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function toGpsLog(Vehicle $vehicle, array $attributes, ?VehicleGpsLog $lastLog): ?array
    {
        if (! isset($attributes['gps_lat'], $attributes['gps_lng'])) {
            return null;
        }

        $recordedAt = $attributes['last_gps_update'];

        if ($lastLog !== null) {
            if ($recordedAt->lte($lastLog->recorded_at)) {
                return null;
            }

            $movedMeters = $this->geo()->calculateHaversineKm(
                (float) $lastLog->lat, (float) $lastLog->lng, $attributes['gps_lat'], $attributes['gps_lng'],
            ) * 1000;
            $statusChanged = ($attributes['car_status'] ?? null) !== $lastLog->car_status;

            if ($movedMeters < self::MIN_MOVE_METERS && ! $statusChanged) {
                return null;
            }
        }

        return [
            'vehicle_id' => $vehicle->id,
            'lat' => $attributes['gps_lat'],
            'lng' => $attributes['gps_lng'],
            'speed' => $attributes['gps_speed'] ?? null,
            'direction' => $attributes['gps_direction'] ?? null,
            'car_status' => $attributes['car_status'] ?? null,
            'address' => $attributes['gps_address'] ?? null,
            'recorded_at' => $recordedAt->format('Y-m-d H:i:s'),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    private function geo(): TripCheckpointOsmValidationService
    {
        return $this->geo ??= app(TripCheckpointOsmValidationService::class);
    }

    /**
     * Mốc bắt đầu "dừng không tắt máy": giữ nguyên khi xe vẫn ở mã 10, đặt mới khi vừa chuyển sang 10, xoá khi rời 10.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function idleEngineSince(Vehicle $vehicle, array $attributes): ?Carbon
    {
        $carStatus = $attributes['car_status'] ?? $vehicle->car_status;

        if ($carStatus !== EupCarStatus::IdleEngine->value) {
            return null;
        }

        return $vehicle->car_status === EupCarStatus::IdleEngine->value && $vehicle->idle_engine_since !== null
            ? $vehicle->idle_engine_since
            : $attributes['last_gps_update'];
    }
}
