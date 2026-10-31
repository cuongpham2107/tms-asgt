<?php

namespace App\Filament\Widgets;

use App\Filament\Traits\HasTripStatistics;
use Filament\Widgets\Concerns\HasJsRenderer;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Vite;
use Livewire\Attributes\Reactive;

/**
 * Toàn bộ thân trang "Thống kê" (bộ lọc, tab, số liệu tổng hợp, bảng, phân trang) do React vẽ.
 * Page chỉ còn header actions (chọn ngày, import, xuất Excel) và state bộ lọc truyền xuống đây.
 */
class TripStatisticsTable extends Widget
{
    use HasJsRenderer;
    use HasTripStatistics;

    protected string $view = 'filament.widgets.trip-statistics-table';

    protected int|string|array $columnSpan = 'full';

    #[Reactive]
    public ?string $startDate = null;

    #[Reactive]
    public ?string $endDate = null;

    #[Reactive]
    public string $activeTab = 'HHHK';

    #[Reactive]
    public ?int $customerId = null;

    #[Reactive]
    public string $vehicleOwner = 'all';

    #[Reactive]
    public ?string $search = null;

    #[Reactive]
    public ?string $chargeStatus = null;

    #[Reactive]
    public int $page = 1;

    #[Reactive]
    public int $perPage = 25;

    /** Page tăng số này sau mỗi lần ghi dữ liệu để widget vẽ lại bảng. */
    #[Reactive]
    public int $dataVersion = 0;

    public function getRenderer(): string
    {
        return Vite::asset('resources/js/filament/widgets/trip-statistics-table.tsx');
    }

    /**
     * @return array<string, mixed>
     */
    public function getRendererConfiguration(): array
    {
        $paginator = $this->getPaginator();
        $tabCounts = $this->getTabCounts();
        $metrics = $this->getSummaryMetrics();
        $showCharges = $this->activeTab === 'external';
        $chargeCounts = $showCharges ? $this->getChargeStatusCounts() : null;

        return [
            ...$this->getReactTableConfiguration($paginator),
            'filters' => [
                'activeTab' => $this->activeTab,
                'search' => (string) $this->search,
                'customerId' => $this->customerId,
                'vehicleOwner' => $this->vehicleOwner,
                'chargeStatus' => $this->chargeStatus,
            ],
            'tabs' => [
                ['key' => 'HHHK', 'label' => 'HHHK (Hàng không)', 'count' => $tabCounts['HHHK'], 'tone' => 'info'],
                ['key' => 'external', 'label' => 'Hàng ngoài', 'count' => $tabCounts['external'], 'tone' => 'warning'],
                ['key' => 'empty', 'label' => 'Xe không hàng', 'count' => $tabCounts['empty'], 'tone' => 'gray'],
            ],
            'chargeFilters' => $chargeCounts === null
                ? []
                : [
                    ['key' => 'locked', 'label' => 'Đã xuất bảng kê (khoá)', 'count' => $chargeCounts['locked'], 'tone' => 'emerald'],
                    ['key' => 'missing', 'label' => 'Chưa có giá', 'count' => $chargeCounts['missing'], 'tone' => 'amber'],
                ],
            'customers' => $this->getCustomerOptions(),
            'metrics' => [
                'trips' => $metrics['total_trips'],
                'records' => $metrics['total_records'],
                'km' => $metrics['total_km'],
                'kmTitle' => 'Có hàng '.number_format($metrics['total_km_loaded'], 1).' · không hàng '.number_format($metrics['total_km_empty'], 1),
                'avgDuration' => $this->formatDuration($metrics['avg_duration']),
                'avgDurationTitle' => $this->formatDurationTooltip($metrics['avg_duration']),
                'waiting22h' => $metrics['count_waiting_22h'],
                'payment' => $metrics['total_payment'],
            ],
            'pagination' => [
                'page' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'perPage' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
