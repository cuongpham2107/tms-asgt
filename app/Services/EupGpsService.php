<?php

namespace App\Services;

use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EupGpsService
{
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

            foreach ($data['result'] as $item) {
                $lastItem = $item;
                $plateNumber = $item['VehicleNo'] ?? null;

                if (empty($plateNumber)) {
                    continue;
                }

                $vehicle = Vehicle::query()->where('plate_number', $plateNumber)->first();

                if ($vehicle === null) {
                    $errors[] = "Không tìm thấy xe {$plateNumber} trong hệ thống";

                    continue;
                }

                $updateData = [];

                if (isset($item['Longitude'])) {
                    $updateData['gps_lng'] = (float) $item['Longitude'];
                }

                if (isset($item['Latitude'])) {
                    $updateData['gps_lat'] = (float) $item['Latitude'];
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

                if (! empty($item['OperateDate'])) {
                    try {
                        $updateData['last_gps_update'] = Carbon::parse($item['OperateDate']);
                    } catch (\Throwable) {
                        $updateData['last_gps_update'] = Carbon::now();
                    }
                } else {
                    $updateData['last_gps_update'] = Carbon::now();
                }

                $vehicle->update($updateData);
                self::trackIdleEngine($vehicle);
                $updated++;
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
     * Mốc bắt đầu trạng thái "Ko tắt máy" (nổ máy, đứng yên) liên tục của xe, null nếu xe không ở trạng thái đó.
     */
    public static function idleEngineSince(int $vehicleId): ?Carbon
    {
        $timestamp = Cache::get(self::idleEngineCacheKey($vehicleId));

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp, config('app.timezone')) : null;
    }

    /**
     * Ghi mốc lần đầu xe vào trạng thái nổ máy đứng yên, xoá mốc khi xe chạy, tắt máy hoặc mất GPS.
     */
    private static function trackIdleEngine(Vehicle $vehicle): void
    {
        $key = self::idleEngineCacheKey($vehicle->id);

        if ($vehicle->car_status === 0 && (float) $vehicle->gps_speed <= 0) {
            Cache::add($key, ($vehicle->last_gps_update ?? Carbon::now())->getTimestamp());

            return;
        }

        Cache::forget($key);
    }

    private static function idleEngineCacheKey(int $vehicleId): string
    {
        return "vehicle:{$vehicleId}:idle_engine_since";
    }
}
