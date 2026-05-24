<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OsrmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    /**
     * Lấy tuyến đường từ OSRM giữa điểm đi và điểm đến.
     *
     * Dùng cho cả admin backend (Filament map) và mobile app.
     *
     * @bodyParam origin_lat float required Vĩ độ điểm xuất phát
     * @bodyParam origin_lng float required Kinh độ điểm xuất phát
     * @bodyParam destination_lat float required Vĩ độ điểm đến
     * @bodyParam destination_lng float required Kinh độ điểm đến
     * @bodyParam waypoints array (optional) Danh sách điểm trung gian [{lat, lng}, ...]
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "distance": 12345.6,
     *     "duration": 1800,
     *     "geometry": {"type": "LineString", "coordinates": [[lng, lat], ...]},
     *     "legs": [...]
     *   }
     * }
     */
    public function route(Request $request, OsrmService $osrm): JsonResponse
    {
        $validated = $request->validate([
            'origin_lat' => 'required|numeric|between:-90,90',
            'origin_lng' => 'required|numeric|between:-180,180',
            'destination_lat' => 'required|numeric|between:-90,90',
            'destination_lng' => 'required|numeric|between:-180,180',
            'waypoints' => 'nullable|array|max:10',
            'waypoints.*.lat' => 'required_with:waypoints|numeric|between:-90,90',
            'waypoints.*.lng' => 'required_with:waypoints|numeric|between:-180,180',
        ]);

        $result = $osrm->getRoute(
            (float) $validated['origin_lat'],
            (float) $validated['origin_lng'],
            (float) $validated['destination_lat'],
            (float) $validated['destination_lng'],
            $validated['waypoints'] ?? [],
        );

        $status = match (true) {
            $result['success'] => 200,
            str_contains($result['error'] ?? '', 'No route') => 404,
            str_contains($result['error'] ?? '', 'status') => 502,
            default => 500,
        };

        return response()->json($result, $status);
    }
}
