<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\ShiftKmCalculatorService;
use App\Services\Trip\CheckpointFactory;
use App\Services\Trip\TripKmLimitService;
use App\Services\TripKmCalculatorService;
use App\Services\TripKmSplitService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                ->orWhereHas('driverSwaps', fn ($q) => $q->where('from_driver_id', $user->id));
        })
            ->whereIn('status', TripStatus::activeStatuses())
            ->where(function ($q) {
                $q->where('is_empty_run', true)
                    ->orWhereHas('orders', fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned]));
            })
            ->with([
                'vehicle',
                'startLocation',
                'endLocation',
                'driverSwaps.toDriver',
                'orders' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints.location',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
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
            ->where(function ($q) {
                $q->where('is_empty_run', true)
                    ->orWhereHas('orders', fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned]));
            })
            ->with([
                'vehicle',
                'startLocation',
                'endLocation',
                'driverSwaps.toDriver',
                'orders' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Assigned])->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints.location',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->with('driver')->orderBy('occurred_at'),
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

        $belongsToDriver = $trip->driver_id === $user->id
            || $trip->driverSwaps()->where('from_driver_id', $user->id)->exists();

        if (! $belongsToDriver) {
            return response()->json(['message' => 'This trip is not assigned to you'], 403);
        }

        $trip->load([
            'vehicle',
            'startLocation',
            'endLocation',
            'driverSwaps.toDriver',
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
                    ->orWhereHas('driverSwaps', fn ($q) => $q->where('from_driver_id', $user->id));
            })
            ->with([
                'vehicle',
                'startLocation',
                'endLocation',
                'shift',
                'driver',
                'driverSwaps.toDriver',
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
            'end_km' => ['required', 'numeric', 'min:0'],
            'completed_at' => 'nullable|date',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
        ]);

        if ($trip->start_km !== null && (float) $validated['end_km'] < (float) $trip->start_km) {
            return response()->json(['message' => [
                'end_km' => ['Km kết thúc ('.$validated['end_km'].') phải lớn hơn hoặc bằng Km bắt đầu ('.$trip->start_km.')'],
            ]], 422);
        }

        if ($trip->vehicle?->current_mileage !== null && (float) $validated['end_km'] < (float) $trip->vehicle->current_mileage) {
            return response()->json(['message' => [
                'end_km' => ['Km kết thúc phải >= km hiện tại của xe ('.number_format((float) $trip->vehicle->current_mileage, 1).' km)'],
            ]], 422);
        }

        $maxCheckpointKm = $trip->checkpoints()->whereNotNull('km_reading')->max('km_reading');
        if ($maxCheckpointKm !== null && (float) $validated['end_km'] < (float) $maxCheckpointKm) {
            return response()->json(['message' => [
                'end_km' => ['Km kết thúc phải >= km cao nhất của chuyến ('.number_format((float) $maxCheckpointKm, 1).' km)'],
            ]], 422);
        }

        $validationResult = app(TripKmLimitService::class)->validate(
            $trip,
            (float) $validated['end_km'],
            CheckpointType::End->value,
        );

        if (! $validationResult['is_valid']) {
            return response()->json(['message' => [
                'end_km' => [$validationResult['message']],
            ]], 422);
        }

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
            DB::transaction(function () use ($trip, $endKm, $completedAt) {
                app(TripKmCalculatorService::class)->calculate($trip, endKm: $endKm);
                $trip->refresh();
                app(ShiftKmCalculatorService::class)->calculateForTrip($trip);

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

                // Cập nhật km hiện tại của xe
                if ($endKm > 0 && $trip->vehicle) {
                    $trip->vehicle->current_mileage = $endKm;
                    $trip->vehicle->save();
                }

                // Tạo checkpoint đảo lái cho từng order trong trip
                app(CheckpointFactory::class)->create(
                    $trip,
                    [
                        'km_reading' => $endKm,
                        'occurred_at' => $completedAt ? Carbon::parse($completedAt) : now(),
                        'gps_lat' => $validated['gps_lat'] ?? null,
                        'gps_lng' => $validated['gps_lng'] ?? null,
                    ],
                    CheckpointType::DriverSwap,
                );
            });
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
                    ->orWhereHas('driverSwaps', fn ($q) => $q->where('from_driver_id', $user->id)->orWhere('to_driver_id', $user->id));
            })
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
            TripStatus::ReturnTrip,
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

            $driverKm = TripKmSplitService::driverKm($trip, (int) $user->id);
            $totalKm += $driverKm['total_km'];
            $totalLoaded += $driverKm['total_km_loaded'];
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
