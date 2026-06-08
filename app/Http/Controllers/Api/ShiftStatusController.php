<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftStatusController extends Controller
{
    /**
     * Trạng thái ca hiện tại + km gần nhất của lái xe.
     *
     * App mobile gọi khi mở lên để biết:
     * - Lái xe có đang trong ca không
     * - Km kết thúc gần nhất (để pre-fill khi bắt đầu ca mới)
     * - Thông tin xe nếu đang trong ca
     *
     * @response array{active_shift: ?DriverShiftResource, last_km: ?float}
     */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();

        $activeShift = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereNull('end_time')
            ->first();

        // Get last completed shift's end_km (for pre-filling start km)
        $lastKm = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereNotNull('end_time')
            ->whereNotNull('end_km')
            ->orderByDesc('end_time')
            ->value('end_km');

        return response()->json([
            'active_shift' => $activeShift ? DriverShiftResource::make($activeShift->load(['driver', 'vehicle', 'shiftVehicles.vehicle'])) : null,
            'last_km' => $lastKm,
        ]);
    }
}
