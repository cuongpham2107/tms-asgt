<?php

namespace App\Filament\Widgets;

use App\Enums\OrderType;
use App\Filament\Widgets\Concerns\FiltersOrdersByPeriod;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;

/**
 * Tỷ lệ đơn Hàng không (HHHK) và Hàng ngoài trong kỳ chọn.
 */
class OrderTypeChartWidget extends Widget
{
    use FiltersOrdersByPeriod;
    use HasWidgetShield;

    protected string $view = 'filament.widgets.order-type-chart';

    /**
     * @return array{types: list<array{label: string, count: int, color: string}>, total: int, filters: array<string, string>}
     */
    protected function getViewData(): array
    {
        $counts = $this->ordersInPeriod()
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');

        $types = [
            ['label' => 'Hàng không (HHHK)', 'count' => (int) $counts->get(OrderType::Hhhk->value, 0), 'color' => '#0284c7'],
            ['label' => 'Hàng ngoài', 'count' => (int) $counts->get(OrderType::External->value, 0), 'color' => '#10b981'],
        ];

        return [
            'types' => $types,
            'total' => array_sum(array_column($types, 'count')),
            'filters' => $this->getFilters(),
        ];
    }
}
