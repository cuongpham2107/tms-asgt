<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gọi OSRM API để lấy tuyến đường bám đường thực tế.
 *
 * Dùng chung cho API controller và Filament page (tránh HTTP loop khi gọi từ backend).
 */
class OsrmService
{
    private const OSRM_BASE_URL = 'https://router.project-osrm.org/route/v1/driving/';

    private const CACHE_TTL_MINUTES = 30;

    /**
     * Lấy route giữa 2 điểm (có waypoints tùy chọn).
     *
     * @param  float  $originLat  Vĩ độ điểm xuất phát
     * @param  float  $originLng  Kinh độ điểm xuất phát
     * @param  float  $destinationLat  Vĩ độ điểm đến
     * @param  float  $destinationLng  Kinh độ điểm đến
     * @param  array<int, array{lat: float, lng: float}>  $waypoints  Các điểm trung gian
     * @return array{success: bool, data?: array, message?: string, error?: string}
     */
    public function getRoute(
        float $originLat,
        float $originLng,
        float $destinationLat,
        float $destinationLng,
        array $waypoints = [],
    ): array {
        // Xây dựng chuỗi tọa độ cho OSRM (lưu ý: OSRM dùng lng,lat)
        $coords = [];

        $coords[] = $originLng.','.$originLat;

        foreach ($waypoints as $wp) {
            $coords[] = $wp['lng'].','.$wp['lat'];
        }

        $coords[] = $destinationLng.','.$destinationLat;

        $coordsString = implode(';', $coords);

        // Cache theo hash của tọa độ
        $cacheKey = 'osrm_route_'.md5($coordsString);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($coordsString): array {
            try {
                $response = Http::timeout(10)
                    ->get(self::OSRM_BASE_URL.$coordsString, [
                        'overview' => 'full',
                        'geometries' => 'geojson',
                        'steps' => 'true',
                        'alternatives' => 'false',
                    ]);

                if (! $response->successful()) {
                    Log::warning('OSRM routing failed', [
                        'coords' => $coordsString,
                        'status' => $response->status(),
                    ]);

                    return self::error('Không thể tìm tuyến đường. Vui lòng thử lại.', 'OSRM returned status '.$response->status());
                }

                $data = $response->json();

                if ($data['code'] !== 'Ok' || empty($data['routes'])) {
                    return self::error('Không tìm thấy tuyến đường giữa 2 điểm này.', $data['code'] ?? 'No route');
                }

                $route = $data['routes'][0];

                return [
                    'success' => true,
                    'data' => [
                        'distance' => round($route['distance']),
                        'duration' => round($route['duration']),
                        'geometry' => $route['geometry'],
                        'legs' => $route['legs'] ?? [],
                    ],
                ];
            } catch (\Throwable $e) {
                Log::error('OSRM routing exception', [
                    'coords' => $coordsString,
                    'error' => $e->getMessage(),
                ]);

                return self::error('Lỗi kết nối đến dịch vụ định tuyến.', $e->getMessage());
            }
        });
    }

    /**
     * Lấy route từ mảng các điểm [lat, lng] (dùng cho tracking map).
     *
     * @param  array<int, array{float, float}>  $points  [[lat, lng], [lat, lng], ...]
     * @return array<int, array{float, float}> Tọa độ chi tiết [lat, lng] từ OSRM
     */
    public function getRouteFromPoints(array $points): array
    {
        if (count($points) < 2) {
            return [];
        }

        $origin = $points[0];
        $destination = $points[count($points) - 1];

        $waypoints = [];
        for ($i = 1; $i < count($points) - 1; $i++) {
            $waypoints[] = ['lat' => $points[$i][0], 'lng' => $points[$i][1]];
        }

        $result = $this->getRoute(
            $origin[0],
            $origin[1],
            $destination[0],
            $destination[1],
            $waypoints,
        );

        if (empty($result['success']) || empty($result['data']['geometry']['coordinates'])) {
            return [];
        }

        // Chuyển GeoJSON [lng, lat] → [lat, lng]
        return array_map(
            fn (array $c) => [(float) $c[1], (float) $c[0]],
            $result['data']['geometry']['coordinates']
        );
    }

    private static function error(string $message, string $detail = ''): array
    {
        return [
            'success' => false,
            'message' => $message,
            'error' => $detail,
        ];
    }
}
