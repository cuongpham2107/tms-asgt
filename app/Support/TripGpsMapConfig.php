<?php

namespace App\Support;

use App\Enums\CheckpointType;
use App\Models\Trip;
use App\Models\VehicleGpsPoint;
use EduardoRibeiroDev\FilamentLeaflet\Concerns\HasMapConfig;
use EduardoRibeiroDev\FilamentLeaflet\Enums\TileLayer;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\CircleMarker;
use EduardoRibeiroDev\FilamentLeaflet\Layers\Shapes\Polyline;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Support\Collection;

/**
 * Cấu hình bản đồ Leaflet cho hành trình GPS của một chuyến.
 *
 * Vẽ đường đi tô màu theo từng lượt lái + mốc hành trình, để đối chiếu GPS điện thoại
 * post lên có đúng không. Dùng được ở modal (blade tĩnh) lẫn trang, qua getMapData().
 */
class TripGpsMapConfig
{
    use EvaluatesClosures;
    use HasMapConfig;

    private const PALETTE = ['#2563eb', '#16a34a', '#db2777', '#ea580c', '#7c3aed', '#0891b2'];

    private const MAX_DOTS_PER_DRIVER = 300;

    /** @var Collection<int, VehicleGpsPoint>|null */
    private ?Collection $cachedPoints = null;

    public function __construct(private readonly Trip $trip)
    {
        $this->trip->loadMissing(['driverAssignments.driver', 'checkpoints']);
    }

    public function getId(): string
    {
        return 'trip-gps-map-'.$this->trip->id;
    }

    public function hasGpsTrack(): bool
    {
        $bounds = config('gps.bounds');

        return $this->gpsPoints()->filter(fn (VehicleGpsPoint $p) => $this->inBounds($p, $bounds))->count() >= 2;
    }

    public function outOfBoundsCount(): int
    {
        $bounds = config('gps.bounds');

        return $this->gpsPoints()->reject(fn (VehicleGpsPoint $p) => $this->inBounds($p, $bounds))->count();
    }

    /**
     * @return Collection<int, VehicleGpsPoint>
     */
    private function gpsPoints(): Collection
    {
        if ($this->cachedPoints !== null) {
            return $this->cachedPoints;
        }

        $from = $this->trip->started_at;
        $to = $this->trip->completed_at ?? $this->trip->cancelled_at ?? now();

        if ($this->trip->vehicle_id === null || $from === null) {
            return $this->cachedPoints = collect();
        }

        return $this->cachedPoints = VehicleGpsPoint::query()
            ->where('vehicle_id', $this->trip->vehicle_id)
            ->where('source', VehicleGpsPoint::SOURCE_PHONE)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get(['id', 'driver_id', 'lat', 'lng', 'recorded_at']);
    }

    /**
     * @param  array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}|null  $bounds
     */
    private function inBounds(VehicleGpsPoint $point, ?array $bounds): bool
    {
        if ($bounds === null) {
            return true;
        }

        return $point->lat >= $bounds['min_lat'] && $point->lat <= $bounds['max_lat']
            && $point->lng >= $bounds['min_lng'] && $point->lng <= $bounds['max_lng'];
    }

    /**
     * @return array<int, string>
     */
    private function driverNames(): array
    {
        return $this->trip->driverAssignments
            ->mapWithKeys(fn ($a) => [$a->driver_id => $a->driver?->name ?? ('#'.$a->driver_id)])
            ->all();
    }

    /**
     * @return array{0: float, 1: float}
     */
    protected function getMapCenter(): array
    {
        $bounds = config('gps.bounds');
        $first = $this->gpsPoints()->first(fn (VehicleGpsPoint $p) => $this->inBounds($p, $bounds));

        return $first !== null ? [(float) $first->lat, (float) $first->lng] : [21.0278, 105.8342];
    }

    protected function getDefaultZoom(): int
    {
        return 13;
    }

    protected function getMapHeight(): int
    {
        return 520;
    }

    protected function getFitBounds(): bool
    {
        return true;
    }

    protected function getTileLayersUrl(): TileLayer|string|array
    {
        return [
            'Bản đồ đường' => TileLayer::OpenStreetMap,
            'Vệ tinh' => TileLayer::GoogleSatellite,
        ];
    }

    /**
     * Đường đi tô màu theo từng lượt lái + mốc hành trình. Điểm ngoài vùng không vẽ.
     *
     * @return array<int, mixed>
     */
    protected function getShapes(): array
    {
        $bounds = config('gps.bounds');
        $points = $this->gpsPoints();
        $driverIds = $points->pluck('driver_id')->unique()->values();
        $names = $this->driverNames();

        $shapes = [];

        foreach ($points->groupBy('driver_id') as $driverId => $group) {
            $inBounds = $group->filter(fn (VehicleGpsPoint $p) => $this->inBounds($p, $bounds))->values();
            $coords = $inBounds->map(fn (VehicleGpsPoint $p) => [(float) $p->lat, (float) $p->lng])->all();

            if (count($coords) < 2) {
                continue;
            }

            $color = self::PALETTE[max(0, (int) $driverIds->search($driverId)) % count(self::PALETTE)];
            $driverLabel = $names[$driverId] ?? ('#'.$driverId);

            $shapes[] = Polyline::make($coords)
                ->id('gps-driver-'.$driverId)
                ->color($color)
                ->weight(3)
                ->opacity(0.55)
                ->fill(false)
                ->tooltipContent('Lượt lái: '.$driverLabel);

            // Chấm nhỏ mỗi điểm GPS ghi nhận -> thấy mật độ & cách thiết bị ghi.
            // Giới hạn ~MAX_DOTS chấm/lượt lái (chuyến dài thì rải thưa) để modal không quá nặng.
            $dots = $inBounds;
            if ($dots->count() > self::MAX_DOTS_PER_DRIVER) {
                $step = (int) ceil($dots->count() / self::MAX_DOTS_PER_DRIVER);
                $dots = $dots->filter(fn (VehicleGpsPoint $p, int $i) => $i % $step === 0)->values();
            }

            foreach ($dots as $point) {
                $shapes[] = CircleMarker::make((float) $point->lat, (float) $point->lng)
                    ->id('pt-'.$point->id)
                    ->radius(2)
                    ->color($color)
                    ->fillColor($color)
                    ->fillOpacity(0.8)
                    ->weight(1)
                    ->tooltipContent($point->recorded_at?->format('H:i:s') ?? '');
            }
        }

        // Gộp các mốc TRÙNG toạ độ (ví dụ Xuất phát + Đến lấy hàng cùng ở kho) vào 1 marker,
        // tooltip liệt kê đủ để vẫn biết có những mốc nào ở đó. Màu: bắt đầu = xanh, kết thúc = đỏ.
        $byLocation = $this->trip->checkpoints
            ->filter(fn ($cp) => $cp->gps_lat !== null && $cp->gps_lng !== null)
            ->groupBy(fn ($cp) => round((float) $cp->gps_lat, 5).','.round((float) $cp->gps_lng, 5));

        foreach ($byLocation as $group) {
            $sorted = $group->sortBy('occurred_at')->values();
            $first = $sorted->first();
            $types = $sorted->pluck('checkpoint_type');

            [$fillColor, $strokeColor] = match (true) {
                $types->contains(CheckpointType::Started) => ['#22c55e', '#14532d'],
                $types->contains(CheckpointType::End) => ['#ef4444', '#7f1d1d'],
                default => ['#f59e0b', '#1f2937'],
            };

            $labels = $sorted
                ->map(fn ($cp) => $cp->checkpoint_type->getLabel().' ('.($cp->occurred_at?->format('H:i') ?? '').')')
                ->implode(', ');

            $shapes[] = CircleMarker::make((float) $first->gps_lat, (float) $first->gps_lng)
                ->id('cp-'.$first->id)
                ->radius(8)
                ->color($strokeColor)
                ->fillColor($fillColor)
                ->fillOpacity(0.95)
                ->weight(2)
                ->tooltipContent($labels)
                // JS của package đọc cờ từ tooltip.options, nên tooltipPermanent()/Direction() (ghi cấp phẳng)
                // không có tác dụng — phải truyền qua tooltipOptions().
                ->tooltipOptions(['permanent' => true, 'direction' => 'top']);
        }

        return $shapes;
    }
}
