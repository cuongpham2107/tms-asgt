<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Models\Vehicle;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GoogleMapSidebar extends Widget
{
    protected string $view = 'filament.widgets.google-map-sidebar';

    protected int|string|array $columnSpan = 4;

    public array $selectedVehicleIds = [];

    public string $vehicleSearch = '';

    public string $filterStatus = 'all';

    public string $filterVehicleType = 'all';

    public function getVehicles(): array
    {
        return $this->getFilteredVehicles()->map(function (Vehicle $vehicle): array {
            $color = match ($vehicle->status) {
                VehicleStatus::Running => 'amber',
                VehicleStatus::On => 'emerald',
                VehicleStatus::Bdsc => 'red',
                VehicleStatus::Off => 'gray',
                default => 'gray',
            };

            return [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate_number,
                'driver' => $vehicle->driver?->name ?? '—',
                'status_label' => $vehicle->getStatusLabel(),
                'status_color' => $color,
                'selected' => in_array($vehicle->id, $this->selectedVehicleIds, true),
            ];
        })->all();
    }

    public function toggleVehicle(int $id): void
    {
        $this->selectedVehicleIds = in_array($id, $this->selectedVehicleIds, true)
            ? array_values(array_filter($this->selectedVehicleIds, fn (int $v) => $v !== $id))
            : [...$this->selectedVehicleIds, $id];

        $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
    }

    public function selectAll(): void
    {
        $this->selectedVehicleIds = $this->getRawVehicles()->pluck('id')->values()->all();
        $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
    }

    public function deselectAll(): void
    {
        $this->selectedVehicleIds = [];
        $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
    }

    public function getVehicleTypeOptions(): array
    {
        return collect($this->getRawVehicles())
            ->pluck('vehicle_type')
            ->filter()
            ->unique()
            ->mapWithKeys(function ($vt) {
                if (is_object($vt)) {
                    $val = $vt->value ?? $vt->name ?? (string) $vt;
                    $label = method_exists($vt, 'getLabel') ? $vt->getLabel() : ($vt->name ?? $val);
                } elseif (is_array($vt)) {
                    $val = $vt['value'] ?? $vt['name'] ?? (string) $vt;
                    $label = $vt['label'] ?? $vt['name'] ?? $val;
                } else {
                    $val = (string) $vt;
                    $label = $val;
                }

                return [$val => $label];
            })->toArray();
    }

    public static function getRelativeOrder(): int
    {
        return 0;
    }

    private ?Collection $cachedVehicles = null;

    private function getRawVehicles(): Collection
    {
        if ($this->cachedVehicles !== null) {
            return $this->cachedVehicles;
        }

        $activeStatuses = $this->activeOrderStatuses();

        return $this->cachedVehicles = Vehicle::query()
            ->with([
                'driver',
                'driverShifts' => fn ($q) => $q->whereNull('driver_shifts.end_time')->latest('driver_shifts.start_time'),
                'orders' => fn ($q) => $q
                    ->with([
                        'customer',
                        'deliveryPoints.location',
                        'driver',
                        'pickupLocation',
                        'tripCheckpoints' => fn ($q) => $q->orderBy('occurred_at'),
                    ])
                    ->where(fn (Builder $q): Builder => $q
                        ->whereIn('status', $activeStatuses)
                        ->orWhereDate('planned_loading_at', today()))
                    ->orderByDesc('planned_loading_at'),
            ])
            ->where('is_active', true)
            ->get();
    }

    private function getFilteredVehicles(): Collection
    {
        $vehicles = $this->getRawVehicles();

        if ($this->filterStatus !== 'all') {
            $vehicles = $vehicles->filter(fn (Vehicle $v) => ($v->status->value ?? null) === $this->filterStatus || ($v->status->name ?? null) === $this->filterStatus)->values();
        }

        if ($this->filterVehicleType !== 'all') {
            $vehicles = $vehicles->filter(fn (Vehicle $v) => (string) ($v->vehicle_type?->value ?? $v->vehicle_type?->name ?? '') === $this->filterVehicleType)->values();
        }

        return $vehicles;
    }

    private function activeOrderStatuses(): array
    {
        return [
            OrderStatus::Started->value,
            OrderStatus::ArrivedPickup->value,
            OrderStatus::Delivering->value,
            OrderStatus::ArrivedDelivery->value,
            OrderStatus::DriverSwap->value,
        ];
    }
}
