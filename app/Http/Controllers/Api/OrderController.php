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
     * Danh sách đơn hàng được gửi lệnh cho lái xe.
     *
     * Chỉ trả về đơn đã gửi lệnh (status: Sent, InTransit).
     * Đơn ở trạng thái Assigned (chưa gửi lệnh) sẽ không hiển thị.
     * Sắp xếp theo planned_loading_at tăng dần.
     *
     * @response array{data: OrderResource[]}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $orders = Order::query()
            ->with([
                'customer',
                'pickupLocation',
                'deliveryPoints.location',
                'trip.vehicle',
                'tripCheckpoints' => fn ($query) => $query->with('photos')->orderBy('occurred_at'),
            ])
            ->whereHas('trip', fn ($q) => $q->where('driver_id', $user->id))
            ->whereIn('status', [OrderStatus::Sent, OrderStatus::InTransit])
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
     * @pathParam order integer ID đơn hàng. Example: 1001
     *
     * @response array{data: OrderResource}
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        $order->load('trip');
        if ($order->trip?->driver_id !== $user->id) {
            /** @status 403 */
            return response()->json(['message' => 'This order is not assigned to you'], 403);
        }

        // Driver only sees orders that have been sent
        if ($order->status === OrderStatus::Assigned) {
            /** @status 403 */
            return response()->json(['message' => 'Order has not been sent yet'], 403);
        }

        $order->load([
            'customer',
            'pickupLocation',
            'deliveryPoints',
            'trip.vehicle',
            'tripCheckpoints' => fn ($query) => $query->with('photos')->orderBy('occurred_at'),
        ]);

        return response()->json([
            'data' => OrderResource::make($order),
        ]);
    }

    /**
     * Lấy danh sách điểm giao của một đơn hàng để mobile chọn `delivery_point_id`.
     *
     * @pathParam order integer ID đơn hàng. Example: 1001
     *
     * @response array{data: array<int, array{id: int, sequence: int|null, address: string|null}>}
     */
    public function deliveryPoints(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        $order->load('trip');
        if ($order->trip?->driver_id !== $user->id) {
            /** @status 403 */
            return response()->json(['message' => 'This order is not assigned to you'], 403);
        }

        if ($order->status === OrderStatus::Assigned) {
            /** @status 403 */
            return response()->json(['message' => 'Order has not been sent yet'], 403);
        }

        $points = $order->deliveryPoints()
            ->select(['id', 'sequence', 'address'])
            ->orderBy('sequence')
            ->get();

        return response()->json([
            'data' => $points,
        ]);
    }

    /**
     * Lịch sử các chuyến đã hoàn thành của lái xe.
     *
     * Trả về danh sách đơn hàng có trạng thái Completed, kèm deliveryPoints,
     * tripCheckpoints và ảnh trong từng checkpoint. Có phân trang.
     *
     * @queryParam per_page int Số bản ghi mỗi trang (mặc định 15). Example: 10
     *
     * @response array{data: OrderResource[], meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();

        $orders = Order::query()
            ->with([
                'customer',
                'pickupLocation',
                'deliveryPoints.location',
                'trip.vehicle',
                'trip.driverSwaps',
                'tripCheckpoints' => fn ($q) => $q->with('photos')->orderBy('occurred_at'),
            ])
            ->whereHas('trip', function ($q) use ($user) {
                $q->where('driver_id', $user->id)
                    ->orWhereHas('driverSwaps', fn ($q) => $q->where('from_driver_id', $user->id));
            })
            ->where('status', OrderStatus::Completed)
            ->orderBy('updated_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => OrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
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

        $counts = Order::query()
            ->whereHas('trip', fn ($q) => $q->where('driver_id', $user->id))
            ->selectRaw("
                SUM(CASE WHEN status IN ('assigned') THEN 1 ELSE 0 END) as assigned,
                SUM(CASE WHEN status IN ('sent') THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            ")
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
