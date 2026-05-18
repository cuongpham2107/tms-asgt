<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Danh sách đơn hàng được gán cho lái xe.
     *
     * Chỉ trả về đơn đã được điều hành gửi lệnh (trạng thái: Sent → DriverSwap).
     * Sắp xếp theo planned_loading_at tăng dần (đơn cần đóng hàng sớm nhất lên trước).
     *
     * @response array{data: OrderResource[]}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $orders = Order::query()
            ->with(['vehicle', 'deliveryPoints', 'tripCheckpoints'])
            ->where('driver_id', $user->id)
            ->whereIn('status', [
                OrderStatus::Sent,
                OrderStatus::Started,
                OrderStatus::ArrivedPickup,
                OrderStatus::Delivering,
                OrderStatus::ArrivedDelivery,
                OrderStatus::DriverSwap,
            ])
            ->orderBy('planned_loading_at')
            ->get();

        return response()->json([
            'data' => OrderResource::collection($orders),
        ]);
    }

    /**
     * Xem chi tiết đơn hàng của lái xe.
     *
     * Bao gồm: điểm giao nhận (delivery points), lịch sử checkpoint, thông tin xe.
     *
     * @response array{data: OrderResource}
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        // Only allow driver to view their own orders
        if ($order->driver_id !== $user->id) {
            /** @status 403 */
            return response()->json(['message' => 'This order is not assigned to you'], 403);
        }

        $order->load(['vehicle', 'deliveryPoints', 'tripCheckpoints']);

        return response()->json([
            'data' => OrderResource::make($order),
        ]);
    }
}
