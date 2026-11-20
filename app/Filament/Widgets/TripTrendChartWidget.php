<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\StylesDashboardChart;
use App\Models\Order;
use App\Models\Trip;
use App\Services\ShiftScheduleService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;

class TripTrendChartWidget extends ChartWidget
{
    use HasWidgetShield;
    use StylesDashboardChart;

    protected ?string $heading = 'Xu hướng Vận tải & Điều phối Chuyến xe';

    protected ?string $description = 'Theo dõi khối lượng chuyến xe và tiến độ hoàn thành đơn hàng';

    public ?string $filter = '7days';

    protected function getFilters(): ?array
    {
        return [
            '7days' => '7 ngày gần nhất',
            '30days' => '30 ngày qua',
            'month' => 'Tháng này',
        ];
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter ?: '7days';

        $shiftNow = ShiftScheduleService::getShiftWindow()[0];

        $days = match ($activeFilter) {
            '30days' => 30,
            'month' => (int) $shiftNow->day,
            default => 7,
        };

        $windowStart = $shiftNow->copy()->subDays($days - 1)->setTime(8, 0, 0);
        $windowEnd = $shiftNow->copy()->addDay()->setTime(8, 0, 0);

        $trips = Trip::query()
            ->where(function ($q) use ($windowStart, $windowEnd) {
                $q->whereBetween('started_at', [$windowStart, $windowEnd])
                    ->orWhereBetween('completed_at', [$windowStart, $windowEnd])
                    ->orWhere(fn ($sq) => $sq->whereNull('started_at')->whereBetween('created_at', [$windowStart, $windowEnd]));
            })
            ->get(['started_at', 'completed_at', 'created_at']);

        $completedOrders = Order::query()
            ->where('status', 'completed')
            ->whereBetween('planned_loading_at', [$windowStart, $windowEnd])
            ->get(['planned_loading_at']);

        $labels = [];
        $tripCounts = [];
        $orderCompletedCounts = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $shiftNow->copy()->subDays($i);
            $dayStart = $date->copy()->setTime(8, 0, 0);
            $dayEnd = $date->copy()->addDay()->setTime(8, 0, 0);
            $label = $days > 14 ? $date->format('d/m') : $date->format('D d/m');

            $labels[] = $label;

            $tripCounts[] = $trips->filter(function (Trip $trip) use ($dayStart, $dayEnd): bool {
                if ($trip->started_at !== null && $trip->started_at->betweenIncluded($dayStart, $dayEnd)) {
                    return true;
                }

                if ($trip->completed_at !== null && $trip->completed_at->betweenIncluded($dayStart, $dayEnd)) {
                    return true;
                }

                return $trip->started_at === null
                    && $trip->created_at !== null
                    && $trip->created_at->betweenIncluded($dayStart, $dayEnd);
            })->count();

            $orderCompletedCounts[] = $completedOrders->filter(
                fn (Order $order): bool => $order->planned_loading_at !== null
                    && $order->planned_loading_at->betweenIncluded($dayStart, $dayEnd)
            )->count();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Chuyến xe phát sinh',
                    'data' => $tripCounts,
                    'borderColor' => '#0ea5e9',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.10)',
                    'fill' => true,
                    'tension' => 0.35,
                    'borderWidth' => 2,
                    'pointBackgroundColor' => '#0ea5e9',
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 1.5,
                    'pointRadius' => 2.5,
                    'pointHoverRadius' => 5,
                ],
                [
                    'label' => 'Đơn hoàn thành',
                    'data' => $orderCompletedCounts,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.06)',
                    'fill' => true,
                    'tension' => 0.35,
                    'borderWidth' => 2,
                    'pointBackgroundColor' => '#10b981',
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 1.5,
                    'pointRadius' => 2.5,
                    'pointHoverRadius' => 5,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return $this->dashboardChartOptions([
            'plugins' => ['tooltip' => ['mode' => 'index', 'intersect' => false]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
        ]);
    }

    protected function getType(): string
    {
        return 'line';
    }
}
