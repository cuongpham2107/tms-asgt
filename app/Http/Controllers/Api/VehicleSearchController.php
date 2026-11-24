<?php

namespace App\Http\Controllers\Api;

use App\Enums\DriverWorkShift;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Http\Controllers\Controller;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Shift\ShiftScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
                $query->whereLike('plate_number', "%{$q}%", caseSensitive: false);
            })
            ->orderBy('plate_number')
            ->limit(10)
            ->get(['id', 'plate_number', 'vehicle_type', 'load_capacity', 'current_mileage']);

        return response()->json([
            'data' => $vehicles,
        ]);
    }

    /**
     * Danh sách xe công ty đang hoạt động để chọn khi vào ca, kèm xe gợi ý của tài xế.
     *
     * @queryParam q string Tìm theo biển số hoặc 4 số cuối. Example: 8678
     *
     * @response array{data: array<int, array{id: int, plate_number: string, vehicle_type: ?string, vehicle_type_label: string, load_capacity: ?float}>, suggested_vehicle_id: ?int}
     */
    public function shiftOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $companyVehicles = Vehicle::query()
            ->where('is_active', true)
            ->where('type', VehicleOwnerType::Company->value)
            ->orderBy('plate_number')
            ->get(['id', 'plate_number', 'vehicle_type', 'load_capacity', 'current_driver_id', 'even_driver_id', 'odd_driver_id']);

        $suggestedVehicleId = $request->user() instanceof User
            ? $this->resolveSuggestedVehicleId($request->user(), $companyVehicles)
            : null;

        $filtered = $q !== ''
            ? $companyVehicles->filter(fn (Vehicle $v): bool => str_contains(mb_strtolower((string) $v->plate_number), mb_strtolower($q)))->values()
            : $companyVehicles;

        return response()->json([
            'data' => $filtered->map(fn (Vehicle $v): array => [
                'id' => $v->id,
                'plate_number' => $v->plate_number,
                'vehicle_type' => $v->vehicle_type?->value,
                'vehicle_type_label' => $v->getVehicleTypeLabel(),
                'load_capacity' => $v->load_capacity !== null ? (float) $v->load_capacity : null,
            ])->all(),
            'suggested_vehicle_id' => $suggestedVehicleId,
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

    /**
     * @param  Collection<int, Vehicle>  $companyVehicles
     */
    private function resolveSuggestedVehicleId(User $user, Collection $companyVehicles): ?int
    {
        if ($companyVehicles->isEmpty()) {
            return null;
        }

        $activeIds = $companyVehicles->pluck('id')->flip();

        $currentMatch = $companyVehicles->firstWhere('current_driver_id', $user->id);
        if ($currentMatch) {
            return $currentMatch->id;
        }

        $todayShift = ShiftScheduleService::determineShift();
        $primaryColumn = $todayShift === DriverWorkShift::Even ? 'even_driver_id' : 'odd_driver_id';
        $secondaryColumn = $todayShift === DriverWorkShift::Even ? 'odd_driver_id' : 'even_driver_id';

        $shiftMatch = $companyVehicles->firstWhere($primaryColumn, $user->id)
            ?? $companyVehicles->firstWhere($secondaryColumn, $user->id);
        if ($shiftMatch) {
            return $shiftMatch->id;
        }

        if ($user->vehicle_id && $activeIds->has((int) $user->vehicle_id)) {
            return (int) $user->vehicle_id;
        }

        $lastShiftVehicleId = DriverShift::query()
            ->where('driver_id', $user->id)
            ->whereNotNull('vehicle_id')
            ->latest('start_time')
            ->value('vehicle_id');

        if ($lastShiftVehicleId && $activeIds->has((int) $lastShiftVehicleId)) {
            return (int) $lastShiftVehicleId;
        }

        $lastTripVehicleId = Trip::query()
            ->where('driver_id', $user->id)
            ->whereNotNull('vehicle_id')
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->latest('id')
            ->value('vehicle_id');

        if ($lastTripVehicleId && $activeIds->has((int) $lastTripVehicleId)) {
            return (int) $lastTripVehicleId;
        }

        return null;
    }
}
