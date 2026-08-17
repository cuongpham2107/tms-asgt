<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportTripKmRequest;
use App\Models\Trip;
use App\Models\TripKmReport;
use Illuminate\Http\JsonResponse;

class TripKmReportController extends Controller
{
    /**
     * Báo cáo sai lệch số km trên đồng hồ xe.
     *
     * Lái xe gửi số km thực tế trên taplo + ảnh chụp + ghi chú.
     * Tạo bản ghi chờ admin xử lý.
     *
     * @response array{data: array{id: int, trip_id: int, reported_km: float, system_km: float|null, status: string, note: string|null, created_at: string}}
     */
    public function store(ReportTripKmRequest $request, Trip $trip): JsonResponse
    {
        $user = $request->user();

        $belongsToDriver = $trip->driver_id === $user->id
            || $trip->driverSwaps()->where('from_driver_id', $user->id)->exists();

        if (! $belongsToDriver) {
            return response()->json(['message' => 'Bạn không thuộc chuyến này'], 403);
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('km_reports', 'public');
        }

        $systemKm = $trip->vehicle?->current_mileage;

        $report = TripKmReport::create([
            'trip_id' => $trip->id,
            'driver_id' => $user->id,
            'vehicle_id' => $trip->vehicle_id,
            'reported_km' => $request->validated('reported_km'),
            'system_km' => $systemKm,
            'photo_path' => $photoPath,
            'note' => $request->validated('note'),
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => [
                'id' => $report->id,
                'trip_id' => $report->trip_id,
                'reported_km' => $report->reported_km,
                'system_km' => $report->system_km,
                'status' => $report->status->value,
                'note' => $report->note,
                'created_at' => $report->created_at->toIso8601String(),
            ],
        ], 201);
    }
}
