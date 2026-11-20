<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Pages\VehicleDispatchBoard;
use App\Filament\Traits\HasVehicleDispatchRows;
use App\Filament\Widgets\Concerns\StylesDashboardChart;
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
    use StylesDashboardChart;

    /** @var array<string, array{label: string, color: string}> */
    public const STATUSES = [
        'running' => ['label' => 'Chưa xong', 'color' => '#2563eb'],
        'ready' => ['label' => 'Sẵn sàng', 'color' => '#059669'],
        'pending' => ['label' => 'Chờ chạy', 'color' => '#f59e0b'],
        'idle' => ['label' => 'Chưa chạy', 'color' => '#94a3b8'],
    ];

    protected ?string $heading = 'Trạng thái Xe (thời gian thực)';

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
        $total = collect($this->countsByArea())->sum(fn (array $area): int => array_sum($area['counts']));

        return "Tổng {$total} xe công ty (không tính nghỉ / bảo dưỡng) · cập nhật ".now()->format('H:i');
    }

    /**
     * Cả hai chế độ dùng cột chồng, mỗi trạng thái là một dataset: chú thích màu giống nhau,
     * vì Filament chỉ nạp options một lần, đổi bộ lọc chỉ thay dữ liệu.
     */
    protected function getData(): array
    {
        $areas = collect($this->countsByArea());

        if ($this->filter === 'area') {
            return [
                'labels' => $areas->map(fn (array $area): array => [$area['title'], array_sum($area['counts']).' xe'])->values()->all(),
                'datasets' => collect(self::STATUSES)->map(fn (array $status, string $key): array => [
                    'label' => $status['label'],
                    'data' => $areas->pluck("counts.{$key}")->values()->all(),
                    'backgroundColor' => $status['color'],
                    'maxBarThickness' => 48,
                ])->values()->all(),
            ];
        }

        $keys = array_keys(self::STATUSES);
        $totals = collect($keys)->map(fn (string $key): int => (int) $areas->sum("counts.{$key}"));

        return [
            'labels' => collect($keys)->map(fn (string $key, int $index): array => [self::STATUSES[$key]['label'], $totals[$index].' xe'])->all(),
            'datasets' => collect($keys)->map(fn (string $key, int $index): array => [
                'label' => self::STATUSES[$key]['label'],
                'data' => collect($keys)->map(fn (string $other, int $otherIndex): ?int => $otherIndex === $index ? $totals[$index] : null)->all(),
                'backgroundColor' => self::STATUSES[$key]['color'],
                'borderRadius' => 4,
                'maxBarThickness' => 56,
            ])->all(),
        ];
    }

    protected function getOptions(): array
    {
        return $this->dashboardChartOptions([
            'scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true]],
        ]);
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
