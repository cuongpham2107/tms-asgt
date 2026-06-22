<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Models\TripCheckpoint;
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

    public function mount(int|string $record): void
    {
        static::authorizeResourceAccess();

        $this->record = $this->resolveRecord($record)->load([
            'vehicle',
            'driver',
            'orders.deliveryPoints.location',
            'checkpoints.deliveryPoint.location',
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

        $statusLabel = $trip->getStatusLabel();

        $checkpoints = $trip->checkpoints;

        return [
            'order' => [
                'order_code' => $orderCodes ?: '—',
                'status_label' => $statusLabel,
                'vehicle_plate' => $trip->vehicle?->plate_number ?? '—',
                'driver_name' => $trip->driver?->name ?? '—',
            ],
            'checkpoints' => $checkpoints
                ->map(fn (TripCheckpoint $cp): array => [
                    'id' => $cp->id,
                    'type_value' => $cp->checkpoint_type->value,
                    'type_label' => $cp->checkpoint_type->getLabel(),
                    'type_color' => $cp->checkpoint_type->getColor(),
                    'occurred_at' => $cp->occurred_at?->format('H:i d/m/Y') ?? '—',
                    'occurred_at_iso' => $cp->occurred_at?->toIso8601String(),
                    'address' => $cp->deliveryPoint?->address
                        ?? $cp->deliveryPoint?->location?->name
                        ?? '—',
                    'driver_name' => $trip->driver?->name,
                    'km_reading' => $cp->km_reading !== null
                        ? number_format((float) $cp->km_reading, 1, ',', '.').' km'
                        : null,
                    'gps' => ($cp->gps_lat !== null && $cp->gps_lng !== null)
                        ? number_format((float) $cp->gps_lat, 4, ',', '.').', '.number_format((float) $cp->gps_lng, 4, ',', '.')
                        : null,
                    'voice_note' => $cp->voice_note,
                    'photo_count' => $cp->photos->count(),
                ])
                ->toArray(),
        ];
    }
}
