<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Enums\GeoCheckStatus;
use App\Filament\Resources\Trips\Actions\ResolveKmReportAction;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripPhoto;
use App\Services\Gps\TripCheckpointOsmValidationService;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;

class ViewTripTimeline extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TripResource::class;

    protected static ?string $title = 'Hành trình chuyến đi';

    protected static ?string $breadcrumb = 'Hành trình';

    protected string $view = 'filament.resources.trips.pages.view-trip-timeline';

    protected function getHeaderActions(): array
    {
        return [
            ResolveKmReportAction::make(),
        ];
    }

    public function mount(int|string $record): void
    {
        static::authorizeResourceAccess();

        $this->record = $this->resolveRecord($record)->load([
            'vehicle',
            'driver',
            'orders.customer',
            'orders.deliveryPoints.location',
            'checkpoints.driver',
            'checkpoints.deliveryPoint.location',
            'checkpoints.order.pickupLocation',
            'checkpoints.photos',
        ]);
    }

    public function getRecord(): Model
    {
        return $this->record;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTimelineData(): array
    {
        /** @var Trip $trip */
        $trip = $this->getRecord();

        $orders = $trip->orders;
        $orderCodes = $orders->pluck('order_code')->implode(', ');
        $customerCodes = $orders->map(fn ($order): ?string => $order->customer?->code)->filter()->unique()->implode(', ');

        $statusLabel = $trip->getStatusLabel();

        $checkpoints = $trip->checkpoints;

        $driverName = $trip->resolveDriverName();
        $osmValidation = app(TripCheckpointOsmValidationService::class)->validateTrip($trip);

        return [
            'order' => [
                'order_code' => $orderCodes ?: '—',
                'customer_code' => $customerCodes ?: '—',
                'status_label' => $statusLabel,
                'vehicle_plate' => $trip->vehicle?->plate_number ?? '—',
                'driver_name' => $driverName ?? '—',
            ],
            'osm_validation' => $osmValidation,
            'checkpoints' => $checkpoints
                ->map(function (TripCheckpoint $cp) use ($driverName, $osmValidation): array {
                    $geoWarnings = array_values(array_filter([
                        $cp->vehicle_geo_status === GeoCheckStatus::OutOfRange
                            ? 'Gửi khi cách xe '.number_format((int) $cp->vehicle_distance_m, 0, ',', '.').' m'
                            : null,
                        $cp->location_geo_status === GeoCheckStatus::OutOfRange
                            ? 'Xe cách điểm '.number_format((int) $cp->location_distance_m, 0, ',', '.').' m'
                            : null,
                    ]));

                    return [
                        'id' => $cp->id,
                        'type_value' => $cp->checkpoint_type->value,
                        'type_label' => $cp->checkpoint_type->getLabel(),
                        'type_color' => $cp->checkpoint_type->getColor(),
                        'occurred_at' => $cp->occurred_at?->format('H:i d/m/Y') ?? '—',
                        'occurred_at_iso' => $cp->occurred_at?->toIso8601String(),
                        'address' => $cp->pointLocation()?->code
                            ?? $cp->pointLocation()?->name
                            ?? '—',
                        'driver_name' => $cp->driver?->name ?? $driverName,
                        'km_reading' => $cp->km_reading !== null
                            ? number_format((float) $cp->km_reading, 1, ',', '.').' km'
                            : null,
                        'gps' => ($cp->gps_lat !== null && $cp->gps_lng !== null)
                            ? number_format((float) $cp->gps_lat, 4, ',', '.').', '.number_format((float) $cp->gps_lng, 4, ',', '.')
                            : null,
                        'voice_note' => $cp->voice_note,
                        'photo_count' => $cp->photos->count(),
                        'photos' => $cp->photos->map(fn (TripPhoto $photo): array => [
                            'url' => self::resolvePhotoUrl($photo),
                            'is_valid' => self::isPhotoValid($photo),
                        ])->all(),
                        'osm_segment' => $osmValidation['segments'][$cp->id] ?? null,
                        'geo_warnings' => $geoWarnings,
                    ];
                })
                ->toArray(),
        ];
    }

    private static function resolvePhotoUrl(TripPhoto $photo): string
    {
        if (! empty($photo->photo_path)) {
            return asset('storage/'.ltrim($photo->photo_path, '/'));
        }

        if (! empty($photo->photo_url)) {
            if (preg_match('#/storage/(.+)$#', $photo->photo_url, $matches)) {
                return asset('storage/'.ltrim($matches[1], '/'));
            }

            return $photo->photo_url;
        }

        return '';
    }

    private static function isPhotoValid(TripPhoto $photo): bool
    {
        if (empty($photo->photo_path)) {
            return false;
        }

        $fullPath = storage_path('app/public/'.ltrim($photo->photo_path, '/'));

        return file_exists($fullPath) && filesize($fullPath) > 0;
    }
}
