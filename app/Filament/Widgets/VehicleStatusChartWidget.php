<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Traits\HasVehicleDispatchRows;
use App\Models\Vehicle;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;

/**
 * Trạng thái thời gian thực của xe công ty (cùng nguồn với bảng Kiểm soát), tổng hoặc theo khu vực.
 * Xe nghỉ / bảo dưỡng không tính.
 */
class VehicleStatusChartWidget extends ChartWidget
{
    use HasVehicleDispatchRows;
    use HasWidgetShield;

    /** @var array<string, array{label: string, color: string}> */
    public const STATUSES = [
        'running' => ['label' => 'Chưa xong', 'color' => '#3b82f6'],
        'ready' => ['label' => 'Sẵn sàng', 'color' => '#10b981'],
        'pending' => ['label' => 'Chờ chạy', 'color' => '#f59e0b'],
        'idle' => ['label' => 'Chưa chạy', 'color' => '#94a3b8'],
    ];

    protected ?string $heading = 'Trạng thái Xe (thời gian thực)';

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = '30s';

    public ?string $filter = 'total';

    public ?string $searchQuery = null;

    public string $sortBy = 'plate_number';

    public string $sortDirection = 'asc';

    public string $activeOwnerType = 'company';

    protected function getFilters(): ?array
    {
        return [
            'total' => 'Tổng hiện tại',
            'area' => 'Theo khu vực',
        ];
    }

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

    public function getDescription(): ?string
    {
        return 'Xe công ty, không tính xe nghỉ / bảo dưỡng · cập nhật '.now()->format('H:i');
    }

    protected function getData(): array
    {
        $areas = $this->countsByArea();

        if ($this->filter === 'area') {
            return [
                'labels' => collect($areas)->map(fn (array $area): string => $area['title'].' ('.array_sum($area['counts']).')')->values()->all(),
                'datasets' => collect(self::STATUSES)->map(fn (array $status, string $key): array => [
                    'label' => $status['label'],
                    'data' => collect($areas)->pluck("counts.{$key}")->values()->all(),
                    'backgroundColor' => $status['color'],
                    'borderRadius' => 4,
                ])->values()->all(),
            ];
        }

        $totals = collect(self::STATUSES)->map(fn (array $status, string $key): int => (int) collect($areas)->sum("counts.{$key}"));

        return [
            'labels' => collect(self::STATUSES)->map(fn (array $status, string $key): string => $status['label'].' ('.$totals[$key].')')->values()->all(),
            'datasets' => [[
                'label' => 'Tổng '.$totals->sum().' xe',
                'data' => $totals->values()->all(),
                'backgroundColor' => collect(self::STATUSES)->pluck('color')->values()->all(),
                'borderRadius' => 6,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom', 'labels' => ['usePointStyle' => true, 'boxWidth' => 8]],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
