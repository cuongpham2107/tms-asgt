<?php

namespace App\Console\Commands;

use App\Enums\CheckpointType;
use App\Models\Location;
use App\Models\TripCheckpoint;
use App\Services\Trip\TripCheckpointOsmValidationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('locations:suggest-coordinates
    {--apply : Ghi toạ độ gợi ý vào bảng locations}
    {--min-samples=5 : Số mốc GPS tối thiểu của một location}
    {--min-shift=100 : Chỉ gợi ý khi toạ độ mới lệch ít nhất N mét}')]
#[Description('Gợi ý toạ độ location từ vị trí GPS tài xế đã gửi khi lấy/giao hàng')]
class SuggestLocationCoordinatesCommand extends Command
{
    /**
     * Mốc cách điểm giữa quá xa (gửi lúc về nhà, GPS sai...) bị loại khỏi phép tính.
     */
    private const OUTLIER_KM = 5.0;

    public function handle(TripCheckpointOsmValidationService $geo): int
    {
        $minSamples = (int) $this->option('min-samples');
        $minShiftM = (float) $this->option('min-shift');
        $rows = [];

        foreach ($this->collectPointsByLocation() as $locationId => $points) {
            $center = $this->median($points);
            $inliers = $points->filter(
                fn (array $p): bool => $geo->calculateHaversineKm($center['lat'], $center['lng'], $p['lat'], $p['lng']) <= self::OUTLIER_KM
            );

            if ($inliers->count() < $minSamples) {
                continue;
            }

            $suggested = $this->median($inliers);
            $location = Location::find($locationId);
            $shiftM = $location->lat !== null && $location->lng !== null
                ? $geo->calculateHaversineKm((float) $location->lat, (float) $location->lng, $suggested['lat'], $suggested['lng']) * 1000
                : INF;

            if ($shiftM < $minShiftM) {
                continue;
            }

            $rows[] = [
                $location->id,
                $location->code,
                $location->name,
                $location->lat !== null ? "{$location->lat}, {$location->lng}" : '—',
                "{$suggested['lat']}, {$suggested['lng']}",
                is_finite($shiftM) ? round($shiftM).' m' : '—',
                $inliers->count(),
            ];

            if ($this->option('apply')) {
                $location->update(['lat' => $suggested['lat'], 'lng' => $suggested['lng']]);
            }
        }

        $this->table(['ID', 'Mã', 'Tên', 'Toạ độ hiện tại', 'Toạ độ gợi ý', 'Lệch', 'Số mốc'], $rows);
        $this->info(($this->option('apply') ? 'Đã cập nhật ' : 'Có thể cập nhật ').count($rows).' location.');

        return self::SUCCESS;
    }

    /**
     * Toạ độ tài xế gửi tại các mốc lấy hàng (theo điểm lấy của đơn) và giao hàng (theo điểm giao).
     *
     * @return Collection<int, Collection<int, array{lat: float, lng: float}>>
     */
    private function collectPointsByLocation(): Collection
    {
        $pickup = TripCheckpoint::query()
            ->join('orders', 'orders.id', '=', 'trip_checkpoints.order_id')
            ->whereIn('checkpoint_type', [CheckpointType::ArrivedPickup->value, CheckpointType::LeftPickup->value])
            ->whereNotNull('orders.pickup_location_id')
            ->get(['orders.pickup_location_id as location_id', 'trip_checkpoints.gps_lat', 'trip_checkpoints.gps_lng']);

        $delivery = TripCheckpoint::query()
            ->join('order_delivery_points', 'order_delivery_points.id', '=', 'trip_checkpoints.delivery_point_id')
            ->whereIn('checkpoint_type', [CheckpointType::ArrivedDelivery->value, CheckpointType::Completed->value])
            ->whereNotNull('order_delivery_points.location_id')
            ->get(['order_delivery_points.location_id as location_id', 'trip_checkpoints.gps_lat', 'trip_checkpoints.gps_lng']);

        return $pickup->concat($delivery)
            ->filter(fn ($row): bool => $row->gps_lat !== null && $row->gps_lng !== null
                && abs((float) $row->gps_lat) > 0.001 && abs((float) $row->gps_lng) > 0.001)
            ->groupBy('location_id')
            ->map(fn (Collection $group): Collection => $group->map(fn ($row): array => [
                'lat' => (float) $row->gps_lat,
                'lng' => (float) $row->gps_lng,
            ])->values());
    }

    /**
     * @param  Collection<int, array{lat: float, lng: float}>  $points
     * @return array{lat: float, lng: float}
     */
    private function median(Collection $points): array
    {
        return [
            'lat' => round((float) $points->median('lat'), 7),
            'lng' => round((float) $points->median('lng'), 7),
        ];
    }
}
