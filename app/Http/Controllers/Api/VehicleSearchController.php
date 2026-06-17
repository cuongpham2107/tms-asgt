<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleSearchController extends Controller
{
    /**
     * Tìm xe theo biển số (nhập 4 số cuối để chọn nhanh).
     *
     * Lái xe nhập 4 số cuối biển số → trả về danh sách xe đang hoạt động khớp.
     *
     * @queryParam q string 4 số cuối biển số xe. Example: 1234
     *
     * @response array{data: array<int, array{id: int, plate_number: string, vehicle_type: string, load_capacity: float, current_mileage: float}>}
     */
    public function search(Request $request): JsonResponse
    {
        $q = $request->query('q', '');

        $vehicles = Vehicle::query()
            ->where('is_active', true)
            ->when($q !== '', function ($query) use ($q): void {
                $query->where('plate_number', 'like', "%{$q}%");
            })
            ->orderBy('plate_number')
            ->limit(10)
            ->get(['id', 'plate_number', 'vehicle_type', 'load_capacity', 'current_mileage']);

        return response()->json([
            'data' => $vehicles,
        ]);
    }

    /**
     * Danh sách xe đang rảnh (chưa có lái xe).
     *
     * Trả về các xe đang hoạt động và chưa được gán lái (current_driver_id = null).
     * Dùng khi lái xe vào ca để chọn xe trống.
     *
     * @response array{data: array<int, array{id: int, plate_number: string, vehicle_type: string, load_capacity: float, current_mileage: float}>}
     */
    public function available(Request $request): JsonResponse
    {
        $vehicles = Vehicle::query()
            ->where('is_active', true)
            ->whereNull('current_driver_id')
            ->orderBy('plate_number')
            ->get(['id', 'plate_number', 'vehicle_type', 'load_capacity', 'current_mileage']);

        return response()->json([
            'data' => $vehicles,
        ]);
    }

    /**
     * Chi tiết thông tin 1 xe.
     *
     * @response array{id: int, plate_number: string, vehicle_type: string, owner: string, make: ?string, model_year: ?int, load_capacity: ?float, current_mileage: ?float, current_driver_id: ?int, status: string, type: string, notes: ?string}
     */
    public function show(Vehicle $vehicle): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'vehicle_type' => $vehicle->vehicle_type?->value,
                'vehicle_type_label' => $vehicle->getVehicleTypeLabel(),
                'owner' => $vehicle->owner,
                'make' => $vehicle->make,
                'model_year' => $vehicle->model_year,
                'load_capacity' => $vehicle->load_capacity !== null ? (float) $vehicle->load_capacity : null,
                'current_mileage' => $vehicle->current_mileage !== null ? (float) $vehicle->current_mileage : null,
                'current_driver_id' => $vehicle->current_driver_id,
                'status' => $vehicle->status?->value,
                'status_label' => $vehicle->getStatusLabel(),
                'type' => $vehicle->type?->value,
                'type_label' => $vehicle->getTypeLabel(),
                'notes' => $vehicle->notes,
            ],
        ]);
    }
}
