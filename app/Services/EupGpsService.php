<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use Carbon\Carbon;
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

    public function sync(): array
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

            $request = Http::timeout(15)->asForm();

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

                if (isset($item['Direction'])) {
                    $updateData['gps_direction'] = (int) $item['Direction'];
                }

                if (! empty($item['Address'])) {
                    $updateData['gps_address'] = $item['Address'];
                }

                $updateData['last_gps_update'] = Carbon::now();

                $vehicle->update($updateData);
                $this->storeTrackPoint($vehicle, $updateData);
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
     * Lưu điểm hộp đen EUP để lấp khoảng trống khi điện thoại mất tín hiệu.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeTrackPoint(Vehicle $vehicle, array $data): void
    {
        if (! isset($data['gps_lat'], $data['gps_lng'])) {
            return;
        }

        VehicleGpsPoint::create([
            'vehicle_id' => $vehicle->id,
            'driver_id' => $vehicle->current_driver_id,
            'recorded_at' => $data['last_gps_update'],
            'lat' => $data['gps_lat'],
            'lng' => $data['gps_lng'],
            'speed' => $data['gps_speed'] ?? null,
            'heading' => $data['gps_direction'] ?? null,
            'source' => VehicleGpsPoint::SOURCE_EUP,
        ]);
    }
}
