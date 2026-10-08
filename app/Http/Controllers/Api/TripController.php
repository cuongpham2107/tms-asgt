<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssignmentEndReason;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\Trip\TripDriverService;
use App\Services\Trip\TripStateMachine;
use App\Services\TripKmCalculatorService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        $trip = Trip::query()->where(function ($q) use ($user) {
            $q->where('driver_id', $user->id)
                ->orWhereHas('driverAssignments', fn ($q) => $q->where('driver_id', $user->id));
        })
            ->whereIn('status', TripStatus::driverActionableStatuses())
            ->where(function ($q) {
                $q->where('is_empty_run', true)
                    ->orWhereHas('orders', fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned]));
            })
            ->with([
                'vehicle',
                'startLocation',
                'endLocation',
                'driverAssignments.driver',
                'orders' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints.location',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
            ])
            ->orderByRaw('CASE WHEN is_empty_run = 0 THEN 0 ELSE 1 END')
            ->orderByRaw("CASE WHEN status IN ('started', 'arrived_pickup', 'delivering', 'arrived_delivery', 'delivered') THEN 0 WHEN status = 'pending' THEN 1 ELSE 2 END")
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
            ->whereIn('status', TripStatus::driverActionableStatuses())
            ->where(function ($q) {
                $q->where('is_empty_run', true)
                    ->orWhereHas('orders', fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned]));
            })
            ->with([
                'vehicle',
                'startLocation',
                'endLocation',
                'driverAssignments.driver',
                'orders' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints.location',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
            ])
            ->orderByRaw('CASE WHEN is_empty_run = 0 THEN 0 ELSE 1 END')
            ->orderByRaw("CASE WHEN status IN ('started', 'arrived_pickup', 'delivering', 'arrived_delivery', 'delivered') THEN 0 WHEN status = 'pending' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'desc')
            ->first();

        if ($trip === null) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'trip' => TripResource::make($trip),
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

        $belongsToDriver = $trip->driver_id === $user->id
            || $trip->driverAssignments()->where('driver_id', $user->id)->exists();

        if (! $belongsToDriver) {
            return response()->json(['message' => 'This trip is not assigned to you'], 403);
        }

        $trip->load([
            'vehicle',
            'startLocation',
            'endLocation',
            'driverAssignments.driver',
            'orders' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
                'customer',
                'pickupLocation',
                'deliveryPoints.location',
                'tripCheckpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
            ]),
            'checkpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
        ]);

        return response()->json([
            'data' => TripResource::make($trip),
        ]);
    }

    /**
     * Lịch sử các chuyến đã kết thúc của lái xe.
     *
     * Trả về danh sách trip có trạng thái Completed/DriverSwap,
     * kèm orders, checkpoints, lượt lái. Có phân trang và filter.
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

        $validStatuses = [TripStatus::Completed, TripStatus::DriverSwap, TripStatus::Cancelled];

        $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_map(fn ($s) => $s->value, $validStatuses))],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $trips = Trip::query()
            ->where(function ($q) use ($user) {
                $q->where('driver_id', $user->id)
                    ->orWhereHas('driverAssignments', fn ($q) => $q->where('driver_id', $user->id));
            })
            ->with([
                'vehicle',
                'startLocation',
                'endLocation',
                'shift',
                'driver',
                'driverAssignments.driver',
                'orders' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints.location',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
            ])
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
     * Lái xe xin đảo lái: chuyến chuyển sang Đảo lái và chờ điều hành gán lái mới.
     *
     * @response array{data: TripResource}
     */
    #[BodyParameter('reason', type: 'string', description: 'Lý do: shift_handover, cargo_not_unloaded, other.', required: true, example: 'shift_handover')]
    #[BodyParameter('note', type: 'string', description: 'Ghi chú thêm.')]
    public function swap(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', Rule::enum(AssignmentEndReason::class)->only(AssignmentEndReason::driverSwapReasons())],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $trip = app(TripDriverService::class)->requestSwap(
            $trip,
            $request->user(),
            AssignmentEndReason::from($validated['reason']),
            $validated['note'] ?? null,
        );

        return response()->json([
            'data' => TripResource::make($trip->load(['vehicle', 'orders'])),
        ]);
    }

    /**
     * Kết thúc chuyến khi mọi đơn đã giao xong (hoặc đã huỷ).
     *
     * @response array{data: TripResource}
     */
    #[BodyParameter('completed_at', type: 'string', format: 'date-time', description: 'Thời điểm kết thúc chuyến.', example: '2026-07-09T17:30:00Z')]
    public function complete(Request $request, Trip $trip): JsonResponse
    {
        $user = $request->user();

        if ($trip->driver_id !== $user->id) {
            return response()->json(['message' => 'Bạn không phải tài xế được gán cho chuyến này'], 403);
        }

        if (in_array($trip->status, [TripStatus::Completed, TripStatus::Cancelled, TripStatus::DriverSwap], true)) {
            return response()->json(['message' => 'Chuyến đã kết thúc hoặc đang chờ đảo lái'], 422);
        }

        if ($trip->is_empty_run) {
            $activeCargoTrip = Trip::getActiveCargoTripForDriver($user->id, $trip->id);
            if ($activeCargoTrip !== null) {
                $plateNumber = $activeCargoTrip->vehicle?->plate_number ?? ('#'.$activeCargoTrip->id);

                return response()->json([
                    'message' => "Xe {$plateNumber} đang có chuyến hàng thực hiện. Vui lòng hoàn thành hoặc đảo lái trước khi kết thúc chuyến không hàng.",
                ], 422);
            }
        }

        $validated = $request->validate([
            'completed_at' => 'nullable|date',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
        ]);

        $hasOpenOrders = $trip->orders()
            ->whereNotIn('status', OrderStatus::closedStatuses())
            ->exists();

        if ($hasOpenOrders) {
            return response()->json([
                'message' => 'Còn đơn hàng chưa giao xong, hãy dùng Đảo lái nếu cần bàn giao chuyến.',
            ], 422);
        }

        if (isset($validated['completed_at'])) {
            $trip->completed_at = Carbon::parse($validated['completed_at']);
        }

        $gpsLat = isset($validated['gps_lat']) ? (float) $validated['gps_lat'] : null;
        $gpsLng = isset($validated['gps_lng']) ? (float) $validated['gps_lng'] : null;

        app(TripStateMachine::class)->complete($trip, $gpsLat, $gpsLng);

        try {
            app(TripKmCalculatorService::class)->calculate($trip);
            $trip->refresh();
        } catch (\Throwable $e) {
            Log::warning('TripKmCalculatorService on complete failed: '.$e->getMessage());
        }

        $trip->load([
            'vehicle',
            'orders',
            'checkpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
        ]);

        return response()->json([
            'data' => TripResource::make($trip),
        ]);
    }

    /**
     * Thống kê số lượng chuyến theo nhóm trạng thái và tổng số KM của lái xe.
     *
     * @query from_date string|null YYYY-MM-DD
     * @query to_date string|null YYYY-MM-DD
     *
     * @response array{data: array{assigned: int, in_progress: int, completed: int, total_km: float, total_km_loaded: float, total_km_empty: float}}
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->query('from_date');
        $to = $request->query('to_date');

        $trips = Trip::query()
            ->where(function ($q) use ($user) {
                $q->where('driver_id', $user->id)
                    ->orWhereHas('driverAssignments', fn ($q) => $q->where('driver_id', $user->id));
            })
            ->with('driverAssignments')
            ->when($from, fn ($q) => $q->where(fn ($sq) => $sq->whereDate('started_at', '>=', $from)->orWhere(fn ($ssq) => $ssq->whereNull('started_at')->whereDate('created_at', '>=', $from))))
            ->when($to, fn ($q) => $q->where(fn ($sq) => $sq->whereDate('started_at', '<=', $to)->orWhere(fn ($ssq) => $ssq->whereNull('started_at')->whereDate('created_at', '<=', $to))))
            ->get();

        $assigned = 0;
        $inProgress = 0;
        $completed = 0;
        $totalKm = 0.0;
        $totalLoaded = 0.0;

        $inProgressStatuses = [
            TripStatus::Started,
            TripStatus::ArrivedPickup,
            TripStatus::Delivering,
            TripStatus::ArrivedDelivery,
            TripStatus::Delivered,
        ];

        foreach ($trips as $trip) {
            if ((int) $trip->driver_id === (int) $user->id) {
                if ($trip->status === TripStatus::Pending) {
                    $assigned++;
                } elseif (in_array($trip->status, $inProgressStatuses, true)) {
                    $inProgress++;
                }
            }

            if ($trip->status === TripStatus::Completed) {
                $completed++;
            }

            // Ai lái lượt nào thì chỉ hưởng km của lượt lái đó
            $userAssignments = $trip->driverAssignments->where('driver_id', $user->id);
            if ($userAssignments->isNotEmpty() && $userAssignments->contains(fn ($a) => $a->km !== null)) {
                $totalKm += (float) $userAssignments->sum('km');
                $totalLoaded += (float) $userAssignments->sum('km_loaded');
            } elseif ((int) $trip->driver_id === (int) $user->id) {
                $totalKm += (float) ($trip->total_km ?? 0);
                $totalLoaded += (float) ($trip->total_km_loaded ?? 0);
            }
        }

        $totalEmpty = max(0.0, $totalKm - $totalLoaded);

        return response()->json([
            'data' => [
                'assigned' => $assigned,
                'in_progress' => $inProgress,
                'completed' => $completed,
                'total_km' => round($totalKm, 1),
                'total_km_loaded' => round($totalLoaded, 1),
                'total_km_empty' => round($totalEmpty, 1),
            ],
        ]);
    }
}
