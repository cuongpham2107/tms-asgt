<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Vehicle;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

class GoogleMapSidebar extends Widget
{
    protected string $view = 'filament.widgets.google-map-sidebar';

    protected int|string|array $columnSpan = 4;

    public array $selectedVehicleIds = [];

    public string $vehicleSearch = '';

    public string $filterStatus = 'all';

    public string $filterVehicleType = 'all';

    public int $perPage = 30;

    public function mount(array $selectedVehicleIds = []): void
    {
        $this->selectedVehicleIds = array_map('intval', $selectedVehicleIds);
    }

    public function loadMore(): void
    {
        $this->perPage += 30;
    }

    #[On('vehicleSelectionChanged')]
    public function onVehicleSelectionChanged(array $selectedIds = []): void
    {
        $this->selectedVehicleIds = array_values(array_map('intval', $selectedIds));

        if (! empty($this->selectedVehicleIds)) {
            $vehicles = $this->getFilteredVehicles();
            foreach ($this->selectedVehicleIds as $selId) {
                $idx = $vehicles->search(fn ($v) => (int) $v->id === (int) $selId);
                if ($idx !== false && $idx >= $this->perPage) {
                    $this->perPage = $idx + 10;
                }
            }
        }
    }

    public function getVehicles(): array
    {
        $selectedSet = array_flip(array_map('intval', $this->selectedVehicleIds));

        return $this->getFilteredVehicles()
            ->take($this->perPage)
            ->map(function (Vehicle $vehicle) use ($selectedSet): array {
                $status = $vehicle->status instanceof VehicleStatus ? $vehicle->status : VehicleStatus::tryFrom($vehicle->status ?? '');

                $color = match ($status) {
                    VehicleStatus::Running => 'amber',
                    VehicleStatus::On => 'emerald',
                    VehicleStatus::Bdsc => 'red',
                    VehicleStatus::Off => 'gray',
                    default => 'gray',
                };

                $activeTrip = $vehicle->trips
                    ->filter(fn ($t) => in_array($t->status, TripStatus::activeStatuses(), true))
                    ->first();

                $activeOrdersCount = $activeTrip?->orders?->count() ?? 0;

                return [
                    'id' => $vehicle->id,
                    'plate' => $vehicle->plate_number,
                    'driver' => $vehicle->driver?->name ?? 'Chưa gán lái',
                    'driver_phone' => $vehicle->driver?->phone ?? null,
                    'vehicle_type' => $vehicle->getVehicleTypeLabel(),
                    'status' => $status?->value ?? 'off',
                    'status_label' => $vehicle->getStatusLabel(),
                    'status_color' => $color,
                    'selected' => isset($selectedSet[(int) $vehicle->id]),
                    'active_trip_code' => $activeTrip?->trip_code,
                    'active_orders_count' => $activeOrdersCount,
                    'gps_speed' => $vehicle->gps_speed ? (float) $vehicle->gps_speed : null,
                    'last_gps_update' => $vehicle->last_gps_update?->diffForHumans(),
                    'has_gps' => $vehicle->gps_lat !== null && $vehicle->gps_lng !== null,
                ];
            })
            ->all();
    }

    public function getTotalFilteredCount(): int
    {
        return $this->getFilteredVehicles()->count();
    }

    public function hasMoreVehicles(): bool
    {
        return $this->getTotalFilteredCount() > $this->perPage;
    }

    public function selectVehicle(int $id): void
    {
        $id = (int) $id;
        $current = array_map('intval', $this->selectedVehicleIds);
        // If clicking the only selected vehicle, deselect it; otherwise focus on this single vehicle
        $this->selectedVehicleIds = ($current === [$id]) ? [] : [$id];

        $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
    }

    public function toggleCheckbox(int $id): void
    {
        $id = (int) $id;
        $current = array_map('intval', $this->selectedVehicleIds);
        $this->selectedVehicleIds = in_array($id, $current, true)
            ? array_values(array_filter($current, fn (int $v) => $v !== $id))
            : [...$current, $id];

        $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
    }

    public function toggleVehicle(int $id): void
    {
        $this->selectVehicle($id);
    }

    public function selectAll(): void
    {
        $this->selectedVehicleIds = array_map('intval', $this->getFilteredVehicles()->pluck('id')->values()->all());
        $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
    }

    public function deselectAll(): void
    {
        $this->selectedVehicleIds = [];
        $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
    }

    public function selectRunningOnly(): void
    {
        $this->selectedVehicleIds = array_map('intval', $this->getRawVehicles()
            ->where('status', VehicleStatus::Running)
            ->pluck('id')
            ->values()
            ->all());

        $this->dispatch('vehicleSelectionChanged', selectedIds: $this->selectedVehicleIds);
    }

    public function getVehicleTypeOptions(): array
    {
        return collect(VehicleType::cases())
            ->mapWithKeys(fn (VehicleType $vt) => [$vt->value => $vt->getLabel()])
            ->toArray();
    }

    public function updatedVehicleSearch(): void
    {
        $this->cachedVehicles = null;
        $this->perPage = 30;
    }

    public function updatedFilterStatus(): void
    {
        $this->cachedVehicles = null;
        $this->perPage = 30;
    }

    public function updatedFilterVehicleType(): void
    {
        $this->cachedVehicles = null;
        $this->perPage = 30;
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

        return $this->cachedVehicles = Vehicle::query()
            ->with([
                'driver',
                'trips' => fn ($q) => $q
                    ->whereIn('status', TripStatus::activeStatuses())
                    ->with(['orders.customer', 'checkpoints']),
            ])
            ->where('is_active', true)
            ->where('type', VehicleOwnerType::Company)
            ->orderByRaw("CASE status WHEN 'running' THEN 1 WHEN 'on' THEN 2 WHEN 'bdsc' THEN 3 ELSE 4 END")
            ->orderBy('plate_number')
            ->get();
    }

    private function getFilteredVehicles(): Collection
    {
        $vehicles = $this->getRawVehicles();

        if ($this->filterStatus !== 'all') {
            $vehicles = $vehicles->filter(function (Vehicle $v) {
                $statusVal = $v->status instanceof VehicleStatus ? $v->status->value : (string) $v->status;

                return $statusVal === $this->filterStatus;
            })->values();
        }

        if ($this->filterVehicleType !== 'all') {
            $vehicles = $vehicles->filter(function (Vehicle $v) {
                $typeVal = $v->vehicle_type instanceof VehicleType ? $v->vehicle_type->value : (string) $v->vehicle_type;

                return $typeVal === $this->filterVehicleType;
            })->values();
        }

        if (filled($this->vehicleSearch)) {
            $search = Str::lower(trim($this->vehicleSearch));
            $vehicles = $vehicles->filter(function (Vehicle $v) use ($search) {
                $plate = Str::lower($v->plate_number ?? '');
                $driverName = Str::lower($v->driver?->name ?? '');
                $driverPhone = Str::lower($v->driver?->phone ?? '');
                $tripCode = Str::lower($v->trips->first()?->trip_code ?? '');

                return str_contains($plate, $search)
                    || str_contains($driverName, $search)
                    || str_contains($driverPhone, $search)
                    || str_contains($tripCode, $search);
            })->values();
        }

        return $vehicles;
    }
}
