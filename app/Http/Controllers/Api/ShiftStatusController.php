<?php

namespace App\Http\Controllers\Api;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverShiftResource;
use App\Models\DriverShift;
use App\Services\ShiftKmCalculatorService;
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
            ->where('start_time', '<=', now())
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        if ($activeShift) {
            app(ShiftKmCalculatorService::class)->calculate($activeShift);
        }

        $lastKm = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereNotNull('end_km')
            ->orderByDesc('end_time')
            ->value('end_km');

        return response()->json([
            'active_shift' => $activeShift ? DriverShiftResource::make($activeShift->load(['driver', 'trips' => fn ($q) => $q->whereNotIn('status', [TripStatus::Cancelled->value, TripStatus::Pending->value], 'and')->with(['vehicle', 'orders'])])) : null,
            'last_km' => $lastKm,
            'app_version' => [
                'latest_version' => (string) config('app.mobile_latest_version', '1.1.0'),
                'min_version' => (string) config('app.mobile_min_version', '1.0.0'),
                'release_notes' => (string) config('app.mobile_release_notes', 'Cập nhật tính năng và cải thiện hiệu năng hệ thống.'),
                'store_urls' => [
                    'android' => (string) config('app.mobile_android_url', 'https://play.google.com/store/apps/details?id=vn.asgt.tmsdriver'),
                    'ios' => (string) config('app.mobile_ios_url', 'https://apps.apple.com/app/id6810122889'),
                ],
            ],
        ]);
    }
}
