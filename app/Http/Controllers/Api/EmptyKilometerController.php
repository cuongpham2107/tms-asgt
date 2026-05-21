<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmptyKilometerResource;
use App\Models\EmptyKilometer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmptyKilometerController extends Controller
{
    /**
     * Ghi nhận số km không hàng (chạy rỗng) từ điểm bắt đầu đến điểm kết thúc.
     *
     * Dùng khi lái xe di chuyển không có hàng: về depot, đi đón hàng, hoặc
     * di chuyển giữa các điểm không thuộc đơn hàng nào.
     *
     * @bodyParam vehicle_id int Xe đang chạy (nếu có). Example: 10
     * @bodyParam shift_id int Ca trực liên quan. Example: 88
     * @bodyParam start_km float required Km đồng hồ lúc bắt đầu. Example: 12340.5
     * @bodyParam end_km float required Km đồng hồ lúc kết thúc. Example: 12355.2
     * @bodyParam start_gps_lat float Vĩ độ điểm bắt đầu. Example: 10.823099
     * @bodyParam start_gps_lng float Kinh độ điểm bắt đầu. Example: 106.629662
     * @bodyParam end_gps_lat float Vĩ độ điểm kết thúc. Example: 10.850000
     * @bodyParam end_gps_lng float Kinh độ điểm kết thúc. Example: 106.700000
     * @bodyParam started_at string required Thời điểm bắt đầu (ISO 8601). Example: 2026-05-21T08:00:00+07:00
     * @bodyParam ended_at string required Thời điểm kết thúc (ISO 8601). Example: 2026-05-21T08:45:00+07:00
     * @bodyParam note string Ghi chú thêm. Example: Chạy rỗng về depot sau khi giao hàng
     *
     * @response array{data: EmptyKilometerResource}
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'shift_id' => ['nullable', 'integer', 'exists:driver_shifts,id'],
            'start_km' => ['required', 'numeric', 'min:0'],
            'end_km' => ['required', 'numeric', 'min:0', 'gte:start_km'],
            'start_gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'start_gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'end_gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'end_gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $distance = round($validated['end_km'] - $validated['start_km'], 1);

        $record = EmptyKilometer::create([
            'driver_id' => $request->user()->id,
            'vehicle_id' => $validated['vehicle_id'] ?? null,
            'shift_id' => $validated['shift_id'] ?? null,
            'start_km' => $validated['start_km'],
            'end_km' => $validated['end_km'],
            'distance' => $distance,
            'start_gps_lat' => $validated['start_gps_lat'] ?? null,
            'start_gps_lng' => $validated['start_gps_lng'] ?? null,
            'end_gps_lat' => $validated['end_gps_lat'] ?? null,
            'end_gps_lng' => $validated['end_gps_lng'] ?? null,
            'started_at' => $validated['started_at'],
            'ended_at' => $validated['ended_at'],
            'note' => $validated['note'] ?? null,
        ]);

        return response()->json([
            'data' => EmptyKilometerResource::make($record),
        ], 201);
    }
}
