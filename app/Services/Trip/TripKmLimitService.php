<?php

namespace App\Services\Trip;

use App\Enums\CheckpointType;
use App\Models\Trip;
use App\Models\TripCheckpoint;

class TripKmLimitService
{
    public const DELTA_SHORT = 100.0; // km cho các mốc tại điểm bốc/dỡ hoặc đảo lái

    public const DELTA_LONG = 600.0;  // km cho các chặng di chuyển

    /**
     * @return array{
     *     is_valid: bool,
     *     message: ?string,
     *     previous_km: ?float,
     *     previous_type: ?string,
     *     previous_label: string,
     *     max_allowed_km: ?float,
     *     max_delta: float
     * }
     */
    public function validate(
        Trip $trip,
        float $kmReading,
        string|CheckpointType $currentType,
        ?int $orderId = null,
    ): array {
        $currentTypeVal = $currentType instanceof CheckpointType ? $currentType->value : $currentType;

        $previousInfo = $this->getPreviousKmAndType($trip, $orderId);
        $previousKm = $previousInfo['km'];
        $previousType = $previousInfo['type'];
        $previousLabel = $previousInfo['label'];

        $vehicleMileage = $trip->vehicle?->current_mileage !== null ? (float) $trip->vehicle->current_mileage : null;

        // Baseline km for min/max comparison
        $baseKm = $previousKm ?? $vehicleMileage ?? ($trip->start_km !== null ? (float) $trip->start_km : null);

        if ($baseKm === null) {
            return [
                'is_valid' => true,
                'message' => null,
                'previous_km' => null,
                'previous_type' => null,
                'previous_label' => $previousLabel,
                'max_allowed_km' => null,
                'max_delta' => self::DELTA_LONG,
            ];
        }

        $maxDelta = $this->getMaxAllowedDelta($previousType, $currentTypeVal);

        // 1. Kiểm tra lùi Km (Min Km check)
        if ($kmReading < $baseKm) {
            return [
                'is_valid' => false,
                'message' => sprintf(
                    'Số km nhập vào (%.1f km) không được nhỏ hơn số km của mốc \'%s\' (%.1f km).',
                    $kmReading,
                    $previousLabel,
                    $baseKm
                ),
                'previous_km' => $baseKm,
                'previous_type' => $previousType,
                'previous_label' => $previousLabel,
                'max_allowed_km' => $baseKm + $maxDelta,
                'max_delta' => $maxDelta,
            ];
        }

        if ($vehicleMileage !== null && $kmReading < $vehicleMileage) {
            return [
                'is_valid' => false,
                'message' => sprintf(
                    'Số km nhập vào (%.1f km) không được nhỏ hơn số km hiện tại của xe (%.1f km).',
                    $kmReading,
                    $vehicleMileage
                ),
                'previous_km' => $vehicleMileage,
                'previous_type' => 'vehicle',
                'previous_label' => 'Km hiện tại của xe',
                'max_allowed_km' => $vehicleMileage + $maxDelta,
                'max_delta' => $maxDelta,
            ];
        }

        // 2. Kiểm tra vượt trần Km (Max Delta check)
        $effectiveBaseKm = max($baseKm, $vehicleMileage ?? 0);
        $maxAllowedKm = $effectiveBaseKm + $maxDelta;

        if ($kmReading > $maxAllowedKm) {
            return [
                'is_valid' => false,
                'message' => sprintf(
                    'Số km nhập vào (%.1f km) vượt quá giới hạn cho phép (+%.0f km) so với mốc \'%s\' (%.1f km). Tối đa cho phép: %.1f km.',
                    $kmReading,
                    $maxDelta,
                    $previousLabel,
                    $effectiveBaseKm,
                    $maxAllowedKm
                ),
                'previous_km' => $effectiveBaseKm,
                'previous_type' => $previousType,
                'previous_label' => $previousLabel,
                'max_allowed_km' => $maxAllowedKm,
                'max_delta' => $maxDelta,
            ];
        }

        return [
            'is_valid' => true,
            'message' => null,
            'previous_km' => $effectiveBaseKm,
            'previous_type' => $previousType,
            'previous_label' => $previousLabel,
            'max_allowed_km' => $maxAllowedKm,
            'max_delta' => $maxDelta,
        ];
    }

    /**
     * @return array{km: ?float, type: ?string, label: string}
     */
    public function getPreviousKmAndType(Trip $trip, ?int $orderId = null): array
    {
        // 1. Tìm checkpoint gần nhất có km_reading của trip
        $query = TripCheckpoint::where('trip_id', $trip->id)
            ->whereNotNull('km_reading');

        $latestCp = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($latestCp !== null) {
            $typeVal = $latestCp->checkpoint_type instanceof CheckpointType
                ? $latestCp->checkpoint_type->value
                : (string) $latestCp->checkpoint_type;

            return [
                'km' => (float) $latestCp->km_reading,
                'type' => $typeVal,
                'label' => $this->getCheckpointLabel($typeVal),
            ];
        }

        // 2. Nếu chưa có checkpoint nào có km, kiểm tra trip->start_km
        if ($trip->start_km !== null && (float) $trip->start_km > 0) {
            return [
                'km' => (float) $trip->start_km,
                'type' => CheckpointType::Started->value,
                'label' => 'Bắt đầu chuyến',
            ];
        }

        // 3. Fallback: số km hiện tại của xe
        if ($trip->vehicle?->current_mileage !== null) {
            return [
                'km' => (float) $trip->vehicle->current_mileage,
                'type' => 'vehicle',
                'label' => 'Km hiện tại của xe',
            ];
        }

        return [
            'km' => null,
            'type' => null,
            'label' => 'Điểm xuất phát',
        ];
    }

    /**
     * Xác định giới hạn delta tối đa giữa 2 mốc trạng thái.
     *
     * - Chặng tại chỗ / bàn giao (<= 100km):
     *   + 2 -> 3 (arrived_pickup -> left_pickup)
     *   + 4 -> 5 (arrived_delivery -> completed)
     *   + Mốc 7 (driver_swap)
     * - Chặng di chuyển (<= 600km):
     *   + 1 -> 2 (started -> arrived_pickup)
     *   + 3 -> 4 (left_pickup -> arrived_delivery)
     *   + 5 -> 6 (completed -> end)
     *   + 5.i -> 4.i+1 (completed -> arrived_delivery điểm tiếp theo)
     *   + Trường hợp bỏ qua mốc trung gian không có km (ví dụ 2 -> 4, 2 -> 5)
     */
    public function getMaxAllowedDelta(string|CheckpointType|null $previousType, string|CheckpointType $currentType): float
    {
        $prevVal = $previousType instanceof CheckpointType ? $previousType->value : (string) $previousType;
        $currVal = $currentType instanceof CheckpointType ? $currentType->value : (string) $currentType;

        // Mốc 7: Bàn giao xe (đảo lái) luôn <= 100km
        if ($currVal === CheckpointType::DriverSwap->value) {
            return self::DELTA_SHORT;
        }

        // 2 -> 3: Đến điểm nhận -> Đi giao hàng (thời gian bốc hàng tại kho)
        if ($prevVal === CheckpointType::ArrivedPickup->value && $currVal === CheckpointType::LeftPickup->value) {
            return self::DELTA_SHORT;
        }

        // 4 -> 5: Đến điểm giao -> Giao xong (thời gian dỡ hàng tại điểm)
        if ($prevVal === CheckpointType::ArrivedDelivery->value && $currVal === CheckpointType::Completed->value) {
            return self::DELTA_SHORT;
        }

        // Tất cả các chặng di chuyển còn lại: max 600km
        return self::DELTA_LONG;
    }

    public function getCheckpointLabel(string|CheckpointType|null $type): string
    {
        $typeVal = $type instanceof CheckpointType ? $type->value : (string) $type;

        return match ($typeVal) {
            CheckpointType::Started->value => 'Bắt đầu chuyến',
            CheckpointType::ArrivedPickup->value => 'Đến điểm nhận',
            CheckpointType::LeftPickup->value => 'Đi giao hàng',
            CheckpointType::ArrivedDelivery->value => 'Đến điểm giao',
            CheckpointType::Completed->value => 'Giao xong',
            CheckpointType::DriverSwap->value => 'Đảo lái (Bàn giao xe)',
            CheckpointType::End->value => 'Kết thúc đơn',
            'vehicle' => 'Km hiện tại của xe',
            default => 'Mốc trước đó',
        };
    }
}
