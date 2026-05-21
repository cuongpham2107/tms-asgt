<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Order;
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

        $this->record = $this->resolveRecord($record);
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
        /** @var Order $order */
        $order = $this->getRecord();

        return [
            'order' => [
                'order_code' => $order->order_code,
                'status_label' => $order->status?->getLabel() ?? '—',
                'vehicle_plate' => $order->vehicle?->plate_number ?? '—',
                'driver_name' => $order->driver?->name ?? '—',
            ],
            'checkpoints' => $order->tripCheckpoints()
                ->with(['driver', 'deliveryPoint.location', 'photos'])
                ->orderBy('occurred_at', 'desc')
                ->get()
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
                    'driver_name' => $cp->driver?->name,
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
