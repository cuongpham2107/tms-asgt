<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Area;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use Carbon\Carbon;
use Filament\Widgets\Concerns\HasJsRenderer;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Vite;
use Livewire\Attributes\Reactive;

/**
 * Tab "Kế hoạch" ở trang Kiểm soát, do React vẽ: đơn nháp chưa gán lái, xe có giờ đóng hàng dự kiến từ hôm nay,
 * gom theo ngày. Bộ lọc khu vực và tìm kiếm chạy trên client; nút "Gán lái, xe" gọi action của page.
 */
class DispatchPlanTable extends Widget
{
    use HasJsRenderer;

    /** Chỉ dùng trong trang Kiểm soát, không tự hiện trên Bảng điều hành. */
    protected static bool $isDiscovered = false;

    /** Đơn sắp đóng hàng trong khoảng này (phút) được tô cảnh báo. */
    public const SOON_MINUTES = 120;

    /** Lần render đầu chỉ gửi khung để React vẽ skeleton; true thì kèm dữ liệu (React bật sau khi mount). */
    public bool $isReady = false;

    /** Page tăng số này sau khi gán lái, xe hoặc dữ liệu đổi để bảng vẽ lại. */
    #[Reactive]
    public int $dataVersion = 0;

    protected int|string|array $columnSpan = 'full';

    public function getRenderer(): string
    {
        return Vite::asset('resources/js/filament/widgets/dispatch-plan-table.tsx');
    }

    /**
     * @return array<string, mixed>
     */
    public function getRendererConfiguration(): array
    {
        return [
            'loaded' => $this->isReady,
            'areas' => Area::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name'])
                ->map(fn (Area $area): array => ['id' => $area->id, 'code' => (string) $area->code, 'name' => (string) $area->name])
                ->all(),
            'rows' => $this->isReady ? $this->rows() : [],
            'soonMinutes' => self::SOON_MINUTES,
            'currentUserId' => auth()->id(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $now = now();

        return Order::query()
            ->with(['area', 'customer', 'pickupLocation', 'deliveryPoints.location', 'createdBy'])
            ->where('status', OrderStatus::Draft)
            ->orderBy('planned_loading_at')
            ->orderBy('id')
            ->get()
            // Đơn đã hẹn giờ lên trước theo giờ đóng; đơn chưa hẹn giờ xuống cuối.
            ->sortBy(fn (Order $order): int => self::hasPlannedTime($order) ? 0 : 1)
            ->map(fn (Order $order): array => $this->rowPayload($order, $now))
            ->values()
            ->all();
    }

    /**
     * Giờ đóng do người điều phối nhập theo hẹn của khách. Giờ không sau thời điểm tạo đơn
     * là giờ form tự điền lúc tạo, coi như chưa hẹn giờ.
     */
    public static function hasPlannedTime(Order $order): bool
    {
        return $order->planned_loading_at !== null
            && ($order->created_at === null || $order->planned_loading_at->gt($order->created_at));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(Order $order, Carbon $now): array
    {
        $plannedAt = self::hasPlannedTime($order) ? $order->planned_loading_at : null;

        return [
            'id' => $order->id,
            'orderCode' => (string) $order->order_code,
            'type' => $order->type?->getLabel(),
            'isExternal' => $order->type === OrderType::External,
            'areaId' => $order->area_id,
            'areaCode' => $order->area?->code,
            'customerCode' => $order->customer?->code,
            'customerName' => $order->customer?->name,
            'cargoName' => $order->cargo_name,
            'pickup' => $order->pickupLocation?->code ?? $order->pickup_address,
            'pickupTitle' => $order->pickupLocation?->name ?? $order->pickup_address,
            'destinations' => $order->deliveryPoints
                ->map(fn (OrderDeliveryPoint $point): ?string => $point->location?->code ?? $point->address)
                ->filter()
                ->values()
                ->all(),
            'packages' => $order->total_packages !== null ? (int) $order->total_packages : null,
            'weight' => $order->total_weight !== null ? round((float) $order->total_weight, 2) : null,
            'chargeableWeight' => filled($order->chargeable_weight) ? round((float) $order->chargeable_weight, 2) : null,
            'notes' => $order->notes,
            'isReturnTrip' => (bool) $order->is_return_trip,
            'createdBy' => $order->createdBy?->name,
            'createdById' => $order->created_by,
            'hasPlannedTime' => $plannedAt !== null,
            'time' => $plannedAt?->format('H:i d/m'),
            'minutesLeft' => $plannedAt !== null ? (int) floor($now->diffInMinutes($plannedAt, false)) : null,
            'isPastDay' => $plannedAt?->lt($now->copy()->startOfDay()) ?? false,
        ];
    }
}
