<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FiltersOrdersByPeriod;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;

/**
 * Xếp hạng số đơn theo khu vực trong kỳ chọn.
 */
class OrderAreaChartWidget extends Widget
{
    use FiltersOrdersByPeriod;
    use HasWidgetShield;

    protected string $view = 'filament.widgets.order-area-chart';

    /**
     * @return array{rows: list<array{code: string, name: string, count: int}>, total: int, max: int, filters: array<string, string>}
     */
    protected function getViewData(): array
    {
        $rows = $this->ordersInPeriod()
            ->join('areas', 'orders.area_id', '=', 'areas.id')
            ->selectRaw('areas.code as code, areas.name as name, count(orders.id) as count')
            ->groupBy('areas.code', 'areas.name')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row): array => ['code' => (string) $row->code, 'name' => (string) ($row->name ?: $row->code), 'count' => (int) $row->count]);

        return [
            'rows' => $rows->all(),
            'total' => (int) $rows->sum('count'),
            'max' => (int) $rows->max('count'),
            'filters' => $this->getFilters(),
        ];
    }
}
