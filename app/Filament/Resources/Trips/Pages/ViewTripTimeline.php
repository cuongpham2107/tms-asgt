<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\Actions\AdjustTripKmAction;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Services\Trip\TripLegService;
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

    protected function getHeaderActions(): array
    {
        return [
            AdjustTripKmAction::make()
                ->record($this->getRecord()),
        ];
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

        $legService = app(TripLegService::class);
        $distances = $legService->checkpointDistances($trip);
        $legs = $legService->calculateLegs($trip);

        return [
            'order' => [
                'order_code' => $orderCodes ?: '—',
                'status_label' => $statusLabel,
                'vehicle_plate' => $trip->vehicle?->plate_number ?? '—',
                'driver_name' => $trip->driver?->name ?? '—',
            ],
            'legs' => $legs,
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
                    'gps' => ($cp->gps_lat !== null && $cp->gps_lng !== null)
                        ? number_format((float) $cp->gps_lat, 4, ',', '.').', '.number_format((float) $cp->gps_lng, 4, ',', '.')
                        : null,
                    'distance_from_prev' => $distances[$cp->id]['distance_from_prev_km'] ?? null,
                    'is_loaded' => $distances[$cp->id]['is_loaded'] ?? false,
                    'dist_source' => ! empty($distances[$cp->id]['is_adjusted']) ? 'Đã sửa' : ($distances[$cp->id]['source'] ?? null),
                    'is_adjusted' => $distances[$cp->id]['is_adjusted'] ?? false,
                    'original_distance' => $distances[$cp->id]['original_distance_km'] ?? null,
                    'voice_note' => $cp->voice_note,
                    'photo_count' => $cp->photos->count(),
                ])
                ->toArray(),
        ];
    }
}
