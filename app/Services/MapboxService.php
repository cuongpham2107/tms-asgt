<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MapboxService
{
    /**
     * Ngưỡng phân biệt "waypoints thưa" vs "GPS trace dày":
     *   - trip_checkpoints chỉ có 2–6 điểm → Directions API
     *   - GPS trace liên tục (tracker 30s) → Map Matching API
     */
    private const SPARSE_THRESHOLD = 8;

    /** Directions API: tối đa 25 waypoints/request */
    private const DIRECTIONS_MAX_WAYPOINTS = 25;

    /** Map Matching API: tối đa 100 points/request */
    private const MATCHING_MAX_POINTS = 100;

    public function __construct() {}

    /**
     * Nhận mảng [[lng, lat], ...] và trả về GeoJSON LineString coordinates
     * đã được snap lên road network.
     *
     * Chiến lược tự động:
     *   - < SPARSE_THRESHOLD điểm → Directions API (waypoints → route tối ưu)
     *   - >= SPARSE_THRESHOLD điểm → Map Matching API (GPS trace → snap to road)
     *
     * @param  array<int, array{0: float, 1: float}>  $coords
     * @return array{coordinates: array<int, array{0: float, 1: float}>, method: string}|null
     */
    public function matchCoordinates(array $coords): ?array
    {
        if (count($coords) < 2) {
            return null;
        }

        $token = config('services.mapbox.server_token') ?: config('services.mapbox.token');

        if (! $token) {
            return null;
        }

        $cacheKey = 'mapbox_route_'.sha1(json_encode($coords));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($coords, $token): ?array {
            // trip_checkpoints thưa → dùng Directions API
            if (count($coords) < self::SPARSE_THRESHOLD) {
                return $this->getDirectionsRoute($coords, $token);
            }

            // GPS trace dày → thử Map Matching, fallback Directions
            $matched = $this->getMapMatchedRoute($coords, $token);

            return $matched ?? $this->getDirectionsRoute($coords, $token);
        });
    }

    // -------------------------------------------------------------------------
    // Directions API — dùng cho waypoints thưa (trip_checkpoints)
    // -------------------------------------------------------------------------

    /**
     * Gọi Directions API với toàn bộ waypoints trong một request (tối đa 25).
     * Nếu vượt 25 điểm, chia chunk + stitch lại.
     *
     * @param  array<int, array{0: float, 1: float}>  $coords
     * @return array{coordinates: array, method: string}|null
     */
    private function getDirectionsRoute(array $coords, string $token): ?array
    {
        $chunks = $this->chunkCoordinates($coords, self::DIRECTIONS_MAX_WAYPOINTS);
        $stitched = [];

        foreach ($chunks as $index => $chunk) {
            $coordPath = implode(';', array_map(
                fn (array $c): string => $c[0].','.$c[1],
                $chunk
            ));

            $url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$coordPath}";

            try {
                $response = Http::timeout(10)->get($url, [
                    'geometries' => 'geojson',
                    'overview' => 'full',         // trả về geometry đầy đủ
                    'steps' => 'false',         // không cần turn-by-turn
                    'access_token' => $token,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Mapbox Directions request failed', ['error' => $e->getMessage()]);

                continue;
            }

            if (! $response->ok()) {
                Log::warning('Mapbox Directions non-OK', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                continue;
            }

            $part = $response->json('routes.0.geometry.coordinates');

            if (! empty($part)) {
                // Stitch: bỏ điểm đầu của chunk sau để tránh trùng
                $stitched = $index === 0
                    ? array_merge($stitched, $part)
                    : array_merge($stitched, array_slice($part, 1));
            }
        }

        return empty($stitched)
            ? null
            : ['coordinates' => $stitched, 'method' => 'directions'];
    }

    // -------------------------------------------------------------------------
    // Map Matching API — dùng cho GPS trace dày đặc
    // -------------------------------------------------------------------------

    /**
     * Gọi Map Matching API. Chia chunk 100 điểm nếu cần, stitch lại.
     *
     * @param  array<int, array{0: float, 1: float}>  $coords
     * @return array{coordinates: array, method: string}|null
     */
    private function getMapMatchedRoute(array $coords, string $token): ?array
    {
        $chunks = $this->chunkCoordinates($coords, self::MATCHING_MAX_POINTS);
        $stitched = [];

        foreach ($chunks as $index => $chunk) {
            $coordPath = implode(';', array_map(
                fn (array $c): string => $c[0].','.$c[1],
                $chunk
            ));

            $url = "https://api.mapbox.com/matching/v5/mapbox/driving/{$coordPath}";

            try {
                $response = Http::timeout(10)->get($url, [
                    'geometries' => 'geojson',
                    'tidy' => 'true',
                    'access_token' => $token,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Mapbox Map Matching request failed', ['error' => $e->getMessage()]);

                continue;
            }

            if (! $response->ok()) {
                continue;
            }

            $part = $response->json('matchings.0.geometry.coordinates');

            if (! empty($part)) {
                $stitched = $index === 0
                    ? array_merge($stitched, $part)
                    : array_merge($stitched, array_slice($part, 1));
            }
        }

        return empty($stitched)
            ? null
            : ['coordinates' => $stitched, 'method' => 'matching'];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Chia coordinates thành các chunk kích thước $size, có overlap 1 điểm
     * để stitch liền mạch giữa các chunk.
     *
     * @param  array<int, array{0: float, 1: float}>  $coords
     * @return array<int, array<int, array{0: float, 1: float}>>
     */
    private function chunkCoordinates(array $coords, int $size): array
    {
        $total = count($coords);

        if ($total <= $size) {
            return [$coords];
        }

        $chunks = [];
        $step = $size - 1; // overlap 1 điểm giữa chunk liền kề

        for ($i = 0; $i < $total; $i += $step) {
            $chunks[] = array_slice($coords, $i, $size);
        }

        return $chunks;
    }
}
