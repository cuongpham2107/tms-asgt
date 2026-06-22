<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $trip = Trip::whereHas('orders', function ($q) use ($user) {
            $q->where('driver_id', $user->id)
                ->whereIn('status', [
                    OrderStatus::Sent,
                    OrderStatus::Started,
                    OrderStatus::ArrivedPickup,
                    OrderStatus::Delivering,
                    OrderStatus::ArrivedDelivery,
                ]);
        })
            ->whereIn('status', ['pending', 'in_progress'])
            ->with([
                'vehicle',
                'orders' => fn ($q) => $q->with([
                    'customer',
                    'pickupLocation',
                    'deliveryPoints',
                    'tripCheckpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
                ]),
                'checkpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
            ])
            ->first();

        if ($trip === null) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => TripResource::make($trip),
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

        $belongsToDriver = $trip->orders()
            ->where('driver_id', $user->id)
            ->exists();

        if (! $belongsToDriver) {
            return response()->json(['message' => 'This trip is not assigned to you'], 403);
        }

        $trip->load([
            'vehicle',
            'orders' => fn ($q) => $q->with([
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
}
