<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Pages\DriverMonthlyKmReport;
use App\Models\Order;
use App\Services\ShiftScheduleService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bộ lọc kỳ (tháng / tuần / hôm nay / toàn bộ) theo ngày dự kiến đóng hàng, dùng cho các thẻ đơn hàng trên dashboard.
 */
trait FiltersOrdersByPeriod
{
    public ?string $filter = 'month';

    /**
     * @return array<string, string>
     */
    public function getFilters(): array
    {
        return [
            'month' => 'Tháng này',
            'week' => 'Tuần này',
            'today' => 'Hôm nay',
            'all' => 'Toàn bộ thời gian',
        ];
    }

    /**
     * @return Builder<Order>
     */
    protected function ordersInPeriod(): Builder
    {
        $filter = $this->filter ?: 'month';
        $query = Order::query();

        if ($filter !== 'all') {
            $shiftDate = ShiftScheduleService::getShiftWindow()[0]->toDateString();
            $query->whereBetween('orders.planned_loading_at', DriverMonthlyKmReport::rangeFor($filter, $shiftDate));
        }

        return $query;
    }
}
