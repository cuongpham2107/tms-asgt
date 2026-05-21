<?php

namespace App\Http\Controllers;

use App\Services\MapboxService;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapboxController extends Controller
{
    public function __construct(
        protected readonly MapboxService $mapbox
    ) {}

    /**
     * Khớp đường đi / tối ưu lộ trình tọa độ qua Mapbox.
     *
     * @response array{geometry: array{type: string, coordinates: array<int, array{0: float, 1: float}>}, matched: bool, method: string}
     */
    #[BodyParameter('coordinates', type: 'array', description: 'Danh sách tọa độ [lng, lat] cần khớp đường.', required: true, example: [[106.629662, 10.823099], [106.6580, 10.8188]])]
    public function match(Request $request): JsonResponse
    {
        $coords = $request->input('coordinates');

        if (! is_array($coords) || count($coords) < 2) {
            return response()->json(['error' => 'Cần ít nhất 2 tọa độ'], 422);
        }

        // Validate từng điểm là [lng, lat] hợp lệ
        foreach ($coords as $point) {
            if (! is_array($point) || count($point) < 2) {
                return response()->json(['error' => 'Tọa độ không hợp lệ'], 422);
            }
        }

        $token = config('services.mapbox.server_token') ?: config('services.mapbox.token');

        if (! $token) {
            // Trả raw polyline nếu chưa cấu hình token
            return response()->json([
                'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
                'matched' => false,
                'method' => 'raw',
                'error' => 'Chưa cấu hình MAPBOX_SERVER_TOKEN',
            ]);
        }

        $result = $this->mapbox->matchCoordinates($coords);

        if ($result === null) {
            // Fallback: trả thẳng raw coords thay vì error 500
            return response()->json([
                'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
                'matched' => false,
                'method' => 'raw',
                'error' => 'Không thể route qua Mapbox, dùng polyline thẳng',
            ]);
        }

        return response()->json([
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $result['coordinates'],
            ],
            'matched' => true,
            'method' => $result['method'],
            'points' => count($result['coordinates']),
        ]);
    }
}
