<?php

namespace App\Services\Trip;

use App\Enums\OrderDeliveryPointStatus;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Áp dụng dữ liệu sửa đơn hàng từ tab "Đơn hàng" trong form Sửa chuyến.
 * Chỉ cập nhật các trường điều hành được phép sửa tại chỗ, không đụng tới trạng thái / cước.
 */
class TripOrderInlineEditService
{
    /**
     * @param  array<int|string, array<string, mixed>>  $ordersData  key = order_id
     * @return list<int> id các đơn có thay đổi
     */
    public function apply(Trip $trip, array $ordersData): array
    {
        $changedOrderIds = [];

        $trip->loadMissing('orders.deliveryPoints');
        /** @var Collection<int, Order> $orders */
        $orders = $trip->orders->keyBy('id');

        foreach ($ordersData as $orderId => $row) {
            /** @var Order|null $order */
            $order = $orders->get((int) $orderId);

            if (! $order instanceof Order || ! is_array($row)) {
                continue;
            }

            $attributes = [
                'cargo_name' => $this->stringOrNull($row['cargo_name'] ?? null),
                'total_packages' => $this->intOrNull($row['total_packages'] ?? null),
                'total_weight' => $this->decimalOrNull($row['total_weight'] ?? null),
                'pickup_location_id' => $this->intOrNull($row['pickup_location_id'] ?? null),
                'notes' => $this->stringOrNull($row['notes'] ?? null),
            ];

            if (array_key_exists('planned_loading_at', $row)) {
                $attributes['planned_loading_at'] = filled($row['planned_loading_at'] ?? null)
                    ? $row['planned_loading_at']
                    : null;
            }

            if ($order->isExternal()) {
                $attributes['chargeable_weight'] = $this->decimalOrNull($row['chargeable_weight'] ?? null);
            }

            $order->fill($attributes);
            $fieldsChanged = $order->isDirty();

            if ($fieldsChanged) {
                $order->save();
            }

            $pointsChanged = $this->syncDeliveryPoints($order, $row['deliveryPoints'] ?? []);

            if ($fieldsChanged || $pointsChanged) {
                $changedOrderIds[] = (int) $order->id;
            }
        }

        return $changedOrderIds;
    }

    /**
     * Đồng bộ điểm giao theo đúng thứ tự trên form.
     * Điểm chờ giao bị bỏ khỏi form thì xoá; điểm đã đến / đã giao được giữ lại để bảo toàn lịch sử.
     */
    private function syncDeliveryPoints(Order $order, mixed $items): bool
    {
        if (! is_array($items)) {
            return false;
        }

        $signatureBefore = $order->deliveryPointsSignature();

        /** @var Collection<int, OrderDeliveryPoint> $existing */
        $existing = $order->deliveryPoints()->orderBy('id')->get()->keyBy('id');
        $keptIds = [];
        $sequence = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $locationId = $this->intOrNull($item['location_id'] ?? null);
            if ($locationId === null) {
                continue;
            }

            $attributes = [
                'location_id' => $locationId,
                'address' => $this->stringOrNull($item['address'] ?? null),
                'contact_person' => $this->stringOrNull($item['contact_person'] ?? null),
                'contact_phone' => $this->stringOrNull($item['contact_phone'] ?? null),
                'total_packages' => $this->intOrNull($item['total_packages'] ?? null),
                'total_weight' => $this->decimalOrNull($item['total_weight'] ?? null),
                'sequence' => ++$sequence,
            ];

            $pointId = $this->intOrNull($item['id'] ?? null);

            if ($pointId !== null && $existing->has($pointId)) {
                $point = $existing->get($pointId);
                $point->fill($attributes);

                if ($point->isDirty()) {
                    $point->save();
                }

                $keptIds[$pointId] = true;

                continue;
            }

            $order->deliveryPoints()->create($attributes + [
                'status' => OrderDeliveryPointStatus::Pending,
            ]);
        }

        foreach ($existing as $pointId => $point) {
            if (isset($keptIds[$pointId])) {
                continue;
            }

            if ($point->status === OrderDeliveryPointStatus::Pending) {
                $point->delete();

                continue;
            }

            $point->update(['sequence' => ++$sequence]);
        }

        return $signatureBefore !== $order->refresh()->deliveryPointsSignature();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function decimalOrNull(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (string) $value;
    }
}
