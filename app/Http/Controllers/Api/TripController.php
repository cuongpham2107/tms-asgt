<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    /**
     * Lấy chuyến đang hoạt động của lái xe.
     *
     * Trả về trip đang in_progress trên xe mà lái xe có đơn đang chạy,
     * kèm danh sách orders và checkpoints trong trip đó.
     *
     * @response array{data: TripResource|null}
     */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();

        $trip = Trip::where('driver_id', $user->id)
            ->whereIn('status', TripStatus::activeStatuses())
            ->with([
                'vehicle',
                'driverSwaps.toDriver',
                'orders' => fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', [OrderStatus::Draft])->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $trip->isNotEmpty() ? TripResource::collection($trip) : null,
        ]);
    }

    /**
     * Lấy chuyến hiện tại của lái xe kèm số km xe.
     *
     * Trả về trip đang active gần nhất, kèm vehicle_mileage ở top-level.
     *
     * @response array{data: {trip: TripResource, vehicle_mileage: int|null}|null}
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        $trip = Trip::where('driver_id', $user->id)
            ->whereIn('status', TripStatus::activeStatuses())
            ->with([
                'vehicle',
                'driverSwaps.toDriver',
                'orders' => fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', [OrderStatus::Draft])->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
            ])
            ->orderBy('created_at', 'desc')
            ->first();

        if ($trip === null) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'trip' => TripResource::make($trip),
                'vehicle_mileage' => $trip->vehicle?->current_mileage,
            ],
        ]);
    }

    /**
     * Xem chi tiết một chuyến.
     *
     * @pathParam trip integer ID chuyến. Example: 1
     *
     * @response array{data: TripResource}
     */
    public function show(Request $request, Trip $trip): JsonResponse
    {
        $user = $request->user();

        $belongsToDriver = $trip->driver_id === $user->id;

        if (! $belongsToDriver) {
            return response()->json(['message' => 'This trip is not assigned to you'], 403);
        }

        $trip->load([
            'vehicle',
            'driverSwaps.toDriver',
            'orders' => fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
                'customer',
                'pickupLocation',
                'deliveryPoints',
                'tripCheckpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
            ]),
            'checkpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
        ]);

        return response()->json([
            'data' => TripResource::make($trip),
        ]);
    }

    /**
     * Lịch sử các chuyến đã kết thúc của lái xe.
     *
     * Trả về danh sách trip có trạng thái Completed/DriverSwap,
     * kèm orders, checkpoints, driverSwaps. Có phân trang và filter.
     *
     * @queryParam per_page int Số bản ghi mỗi trang (mặc định 15). Example: 10
     * @queryParam from_date string Lọc từ ngày (started_at >=, ISO date). Example: 2026-06-01
     * @queryParam to_date string Lọc đến ngày (started_at <=, ISO date). Example: 2026-06-23
     * @queryParam status string Lọc theo trạng thái trip (completed, driver_swap). Example: completed
     * @queryParam vehicle_id int Lọc theo ID phương tiện. Example: 1
     *
     * @response array{data: TripResource[], meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $validStatuses = [TripStatus::Completed, TripStatus::DriverSwap];

        $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($s) => $s->value, $validStatuses))],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $trips = Trip::query()
            ->with([
                'vehicle',
                'shift',
                'driver',
                'driverSwaps.toDriver',
                'orders' => fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', [OrderStatus::Draft])->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints.location',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
            ])
            ->where('driver_id', $user->id)
            ->whereIn('status', $validStatuses)
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('started_at', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('started_at', '<=', $request->to_date))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->orderBy('started_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => TripResource::collection($trips),
            'meta' => [
                'current_page' => $trips->currentPage(),
                'last_page' => $trips->lastPage(),
                'per_page' => $trips->perPage(),
                'total' => $trips->total(),
            ],
        ]);
    }

    /**
     * Kết thúc chuyến (manual complete).
     *
     * Nếu tất cả orders đã Completed → trip.status = Completed.
     * Nếu còn orders chưa hoàn thành → trip.status = DriverSwap,
     *   tính partial km, orders chưa xong → DriverSwap.
     *
     * @response array{data: TripResource}
     */
    #[BodyParameter('end_km', type: 'number', description: 'Số km đồng hồ lúc kết thúc chuyến.', required: true)]
    #[BodyParameter('completed_at', type: 'string', format: 'date-time', description: 'Thời điểm kết thúc chuyến.', example: '2026-07-09T17:30:00Z')]
    public function complete(Request $request, Trip $trip): JsonResponse
    {
        $user = $request->user();

        if ($trip->driver_id !== $user->id) {
            return response()->json(['message' => 'Bạn không phải tài xế được gán cho chuyến này'], 403);
        }

        if ($trip->isCompleted() || $trip->status === TripStatus::DriverSwap) {
            return response()->json(['message' => 'Chuyến đã kết thúc'], 422);
        }

        $validated = $request->validate([
            'end_km' => 'required|numeric|min:0',
            'completed_at' => 'nullable|date',
        ]);

        $endKm = (float) $validated['end_km'];
        $completedAt = $validated['completed_at'] ?? null;

        $allOrdersDone = $trip->orders()
            ->where('status', '!=', OrderStatus::Completed)
            ->doesntExist();

        if ($allOrdersDone) {
            // Tất cả orders đã xong → complete bình thường
            $trip->complete(endKm: $endKm, completedAt: $completedAt);
        } else {
            // Còn orders chưa xong → driver_swap, tính partial km
            \Illuminate\Support\Facades\DB::transaction(function () use ($trip, $endKm, $completedAt) {
                app(\App\Services\TripKmCalculatorService::class)->calculate($trip, endKm: $endKm);
                $trip->refresh();

                $trip->end_km = $endKm;
                $trip->status = TripStatus::DriverSwap;
                $trip->save();

                $trip->orders()
                    ->whereIn('status', [
                        OrderStatus::Sent->value,
                        OrderStatus::InTransit->value,
                        OrderStatus::Assigned->value,
                    ])
                    ->update(['status' => OrderStatus::DriverSwap->value]);
            });
        }

        $trip->load([
            'vehicle',
            'orders' => fn ($q) => $q->whereNull('deleted_at'),
            'checkpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
        ]);

        return response()->json([
            'data' => TripResource::make($trip),
        ]);
    }

    /**
     * Thống kê số lượng đơn hàng theo nhóm trạng thái của lái xe.
     *
     * @response array{data: array{assigned: int, in_progress: int, completed: int}}
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $counts = Trip::query()
            ->where('driver_id', $user->id)
            ->selectRaw('
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as assigned,
                SUM(CASE WHEN status IN (?, ?, ?, ?, ?, ?) THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed
            ', [
                TripStatus::Pending->value,
                TripStatus::Started->value,
                TripStatus::ArrivedPickup->value,
                TripStatus::Delivering->value,
                TripStatus::ArrivedDelivery->value,
                TripStatus::Delivered->value,
                TripStatus::DriverSwap->value,
                TripStatus::Completed->value,
            ])
            ->first();

        return response()->json([
            'data' => [
                'assigned' => (int) ($counts->assigned ?? 0),
                'in_progress' => (int) ($counts->in_progress ?? 0),
                'completed' => (int) ($counts->completed ?? 0),
            ],
        ]);
    }
}
