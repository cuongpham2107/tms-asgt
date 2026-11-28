<?php

namespace App\Filament\Widgets;

use App\Enums\TripStatus;
use App\Filament\Widgets\Concerns\BuildsShiftTrendBuckets;
use App\Filament\Widgets\Concerns\StylesDashboardChart;
use App\Models\Order;
use App\Models\Trip;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class TripTrendChartWidget extends ChartWidget
{
    use BuildsShiftTrendBuckets;
    use HasWidgetShield;
    use StylesDashboardChart;

    protected ?string $heading = 'Xu hướng chuyến xe (ca 8h–8h+)';

    public ?string $filter = '7days';

    protected function getFilters(): ?array
    {
        return $this->shiftTrendFilters();
    }

    /**
     * Số kỳ hiện tại (hôm nay / tuần này / tháng này) của từng đường, kèm chênh lệch so với kỳ liền trước.
     */
    public function getDescription(): string|Htmlable|null
    {
        $bucketMeta = $this->resolveShiftTrendBuckets($this->filter);
        $periodLabel = $bucketMeta['periodLabel'];
        $comparisonTitle = $bucketMeta['comparisonTitle'];

        $parts = collect($this->getCachedData()['datasets'])->map(function (array $dataset) use ($periodLabel, $comparisonTitle): string {
            $values = array_values($dataset['data']);
            $count = count($values);
            $today = $count > 0 ? (int) $values[$count - 1] : 0;
            $yesterday = $count > 1 ? (int) $values[$count - 2] : 0;
            $delta = $today - $yesterday;
            $deltaClass = $delta > 0 ? 'text-emerald-600' : ($delta < 0 ? 'text-rose-600' : 'text-gray-400');
            $deltaText = $delta === 0 ? '±0' : sprintf('%+d', $delta);

            return '<span class="mr-3 inline-flex items-baseline gap-1" title="'.e($comparisonTitle).'"><span class="size-2 self-center rounded-full" style="background: '.e($dataset['borderColor']).'"></span>'
                .e($dataset['label']).' '.e($periodLabel).' <b class="text-sm font-bold tabular-nums text-gray-950 dark:text-white">'.$today.'</b>'
                .'<span class="text-xs tabular-nums '.$deltaClass.'">'.$deltaText.'</span></span>';
        });

        return new HtmlString($parts->implode(''));
    }

    protected function getData(): array
    {
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'buckets' => $buckets] = $this->resolveShiftTrendBuckets($this->filter);

        $trips = Trip::query()
            ->where('status', '!=', TripStatus::Cancelled->value)
            ->where(function ($q) use ($windowStart, $windowEnd): void {
                $q->where(fn ($sq) => $sq->where('completed_at', '>=', $windowStart)->where('completed_at', '<', $windowEnd))
                    ->orWhere(fn ($sq) => $sq->where('started_at', '>=', $windowStart)->where('started_at', '<', $windowEnd))
                    ->orWhere(fn ($sq) => $sq->whereNull('started_at')->where('created_at', '>=', $windowStart)->where('created_at', '<', $windowEnd));
            })
            ->get(['status', 'started_at', 'completed_at', 'created_at']);

        $completedOrders = Order::query()
            ->where('status', 'completed')
            ->where('planned_loading_at', '>=', $windowStart)
            ->where('planned_loading_at', '<', $windowEnd)
            ->get(['planned_loading_at']);

        $labels = [];
        $completedTripCounts = [];
        $totalTripCounts = [];
        $orderCompletedCounts = [];

        foreach ($buckets as $bucket) {
            $dayStart = $bucket['start'];
            $dayEnd = $bucket['end'];
            $labels[] = $bucket['label'];

            $completedTripCounts[] = $trips->filter(
                fn (Trip $trip): bool => $trip->status === TripStatus::Completed
                    && $trip->completed_at !== null
                    && $trip->completed_at->gte($dayStart)
                    && $trip->completed_at->lt($dayEnd)
            )->count();

            $totalTripCounts[] = $trips->filter(function (Trip $trip) use ($dayStart, $dayEnd): bool {
                $effectiveAt = $trip->started_at ?? $trip->completed_at ?? $trip->created_at;

                return $effectiveAt !== null
                    && $effectiveAt->gte($dayStart)
                    && $effectiveAt->lt($dayEnd);
            })->count();

            $orderCompletedCounts[] = $completedOrders->filter(
                fn (Order $order): bool => $order->planned_loading_at !== null
                    && $order->planned_loading_at->gte($dayStart)
                    && $order->planned_loading_at->lt($dayEnd)
            )->count();
        }

        return [
            'datasets' => [
                $this->buildTrendLineDataset('Chuyến hoàn thành', $completedTripCounts, '#10b981', 'rgba(16, 185, 129, 0.10)', true),
                $this->buildTrendLineDataset('Tổng chuyến', $totalTripCounts, '#0ea5e9', 'rgba(14, 165, 233, 0.06)', true),
                $this->buildTrendLineDataset('Đơn hoàn thành', $orderCompletedCounts, '#8b5cf6', 'rgba(139, 92, 246, 0.04)', false),
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
