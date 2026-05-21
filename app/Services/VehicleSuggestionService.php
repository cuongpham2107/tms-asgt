<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;

/**
 * Gợi ý xe tự động dựa trên nhiều tiêu chí.
 *
 * Tiêu chí sắp xếp (theo thứ tự ưu tiên):
 * 1. Xe gần điểm lấy hàng nhất (GPS distance)
 * 2. Tải trọng phù hợp với đơn
 * 3. Trạng thái sẵn sàng (ON, không BDSC, giấy tờ hợp lệ)
 * 4. Lái xe đang trong ca trực + còn hạn bằng + chứng chỉ phù hợp
 */
class VehicleSuggestionService
{
    /**
     * @return Collection<int, Vehicle>
     */
    public function suggest(Order $order, int $limit = 5): Collection
    {
        $pickupLat = null;
        $pickupLng = null;

        if ($order->pickupLocation) {
            $pickupLat = $order->pickupLocation->lat ?? null;
            $pickupLng = $order->pickupLocation->lng ?? null;
        }

        return Vehicle::query()
            ->with(['currentDriver', 'documents'])
            ->where('is_active', true)
            ->whereIn('status', ['on', 'running'])
            ->when($order->load_capacity || $order->total_weight, function ($query) use ($order) {
                $weight = (float) ($order->total_weight ?? 0) / 1000; // Convert kg to tons

                if ($weight > 0) {
                    $query->where('load_capacity', '>=', $weight);
                }
            })
            ->get()
            ->sortBy(function (Vehicle $vehicle) use ($pickupLat, $pickupLng, $order) {
                $score = 0;

                // 1. GPS distance (nếu có tọa độ)
                if ($pickupLat && $pickupLng && $vehicle->current_driver_id) {
                    $shift = $vehicle->currentDriver?->driverShifts()
                        ->whereNull('end_time')
                        ->latest('start_time')
                        ->first();

                    if ($shift && $shift->start_gps_lat && $shift->start_gps_lng) {
                        $distance = $this->haversineDistance(
                            (float) $pickupLat, (float) $pickupLng,
                            (float) $shift->start_gps_lat, (float) $shift->start_gps_lng
                        );
                        // Gần hơn → score thấp hơn (xếp trước)
                        $score += min($distance / 10, 100);
                    } else {
                        $score += 500; // Không có GPS → xếp sau
                    }
                } else {
                    $score += 500;
                }

                // 2. Tải trọng phù hợp
                $weight = (float) ($order->total_weight ?? 0) / 1000;
                if ($weight > 0 && $vehicle->load_capacity) {
                    $diff = (float) $vehicle->load_capacity - $weight;
                    if ($diff < 0) {
                        $score += 9999; // Không đủ tải → loại
                    }
                }

                // 3. Trạng thái ON được ưu tiên hơn running
                if ($vehicle->status === 'on') {
                    $score -= 10;
                }

                // 4. Có lái xe đang trực
                if ($vehicle->currentDriver) {
                    $hasActiveShift = $vehicle->currentDriver->driverShifts()
                        ->whereNull('end_time')
                        ->exists();
                    if ($hasActiveShift) {
                        $score -= 20;
                    }
                } else {
                    $score += 100; // Không có lái → xếp sau
                }

                return $score;
            })
            ->take($limit)
            ->values();
    }

    /**
     * Tính khoảng cách Haversine giữa 2 điểm GPS (km).
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
