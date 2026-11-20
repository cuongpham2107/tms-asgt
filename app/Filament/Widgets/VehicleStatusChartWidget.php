<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Traits\HasVehicleDispatchRows;
use App\Models\Vehicle;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;

/**
 * Trạng thái thời gian thực của xe công ty (cùng nguồn với bảng Kiểm soát): tổng và theo khu vực.
 * Xe nghỉ / bảo dưỡng không tính.
 */
class VehicleStatusChartWidget extends Widget
{
    use HasVehicleDispatchRows;
    use HasWidgetShield;

    /** @var array<string, array{label: string, color: string}> */
    public const STATUSES = [
        'running' => ['label' => 'Chưa xong', 'color' => '#2563eb'],
        'ready' => ['label' => 'Sẵn sàng', 'color' => '#059669'],
        'pending' => ['label' => 'Chờ chạy', 'color' => '#f59e0b'],
        'idle' => ['label' => 'Chưa chạy', 'color' => '#94a3b8'],
    ];

    protected string $view = 'filament.widgets.vehicle-status-chart';

    public ?string $searchQuery = null;

    public string $sortBy = 'plate_number';

    public string $sortDirection = 'asc';

    public string $activeOwnerType = 'company';

    public static function statusKey(Vehicle $vehicle): ?string
    {
        $trip = $vehicle->active_trip;

        if ($trip === null) {
            if (in_array($vehicle->status, [VehicleStatus::Off, VehicleStatus::Bdsc], true)) {
                return null;
            }

            return VehicleDispatchBoard::getLatestCompletedTripInShift($vehicle) !== null ? 'ready' : 'idle';
        }

        if ($trip->isPaused()) {
            return 'running';
        }

        return match ($trip->status) {
            TripStatus::Pending => 'pending',
            TripStatus::Delivered, TripStatus::ReturnTrip, TripStatus::Completed => 'ready',
            default => 'running',
        };
    }

    /**
     * @return array<string, array{title: string, counts: array<string, int>}>
     */
    public function countsByArea(): array
    {
        return collect($this->getGroupedVehicles())
            ->map(function (array $group): array {
                $keys = $group['vehicles']->map(fn (Vehicle $vehicle): ?string => self::statusKey($vehicle))->filter()->countBy();

                return [
                    'title' => $group['title'],
                    'counts' => collect(self::STATUSES)->map(fn (array $status, string $key): int => (int) $keys->get($key, 0))->all(),
                ];
            })
            ->filter(fn (array $area): bool => array_sum($area['counts']) > 0)
            ->all();
    }

    /**
     * @return array{statuses: array<string, array{label: string, color: string}>, totals: array<string, int>, total: int, areas: array<string, array{title: string, counts: array<string, int>}>, updatedAt: string}
     */
    protected function getViewData(): array
    {
        $areas = $this->countsByArea();
        $totals = collect(self::STATUSES)->map(fn (array $status, string $key): int => (int) collect($areas)->sum("counts.{$key}"))->all();

        return [
            'statuses' => self::STATUSES,
            'totals' => $totals,
            'total' => array_sum($totals),
            'areas' => $areas,
            'updatedAt' => now()->format('H:i'),
        ];
    }
}
