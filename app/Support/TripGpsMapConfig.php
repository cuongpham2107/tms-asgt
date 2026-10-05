<?php

namespace App\Support;

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
            $coords = $group
                ->filter(fn (VehicleGpsPoint $p) => $this->inBounds($p, $bounds))
                ->map(fn (VehicleGpsPoint $p) => [(float) $p->lat, (float) $p->lng])
                ->values()
                ->all();

            if (count($coords) < 2) {
                continue;
            }

            $shapes[] = Polyline::make($coords)
                ->id('gps-driver-'.$driverId)
                ->color(self::PALETTE[max(0, (int) $driverIds->search($driverId)) % count(self::PALETTE)])
                ->weight(4)
                ->opacity(0.85)
                ->fill(false)
                ->tooltipContent('Lượt lái: '.($names[$driverId] ?? ('#'.$driverId)));
        }

        foreach ($this->trip->checkpoints as $cp) {
            if ($cp->gps_lat === null || $cp->gps_lng === null) {
                continue;
            }

            $shapes[] = CircleMarker::make((float) $cp->gps_lat, (float) $cp->gps_lng)
                ->id('cp-'.$cp->id)
                ->radius(7)
                ->color('#1f2937')
                ->fillColor('#f59e0b')
                ->fillOpacity(0.95)
                ->weight(2)
                ->tooltipContent($cp->checkpoint_type->getLabel().' • '.($cp->occurred_at?->format('H:i') ?? ''));
        }

        return $shapes;
    }
}
