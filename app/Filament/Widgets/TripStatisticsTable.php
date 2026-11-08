<?php

namespace App\Filament\Widgets;

use App\Filament\Traits\HasTripStatistics;
use Filament\Widgets\Concerns\HasJsRenderer;
use Filament\Widgets\Widget;
use Illuminate\Pagination\LengthAwarePaginator;
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

    /** Lần render đầu chỉ gửi khung để React vẽ skeleton; true thì kèm dữ liệu (React bật sau khi mount). */
    public bool $isReady = false;

    protected ?string $heading = 'Bảng thống kê chuyến & cước';

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return $this->heading;
    }

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
        // Chưa sẵn sàng: chỉ gửi khung (tab, bộ lọc, cột) với dữ liệu rỗng để React vẽ skeleton, không truy vấn nặng.
        $isReady = $this->isReady;
        $paginator = $isReady ? $this->getPaginator() : new LengthAwarePaginator([], 0, max(10, $this->perPage), 1);
        $tabCounts = $isReady ? $this->getTabCounts() : ['HHHK' => 0, 'external' => 0, 'empty' => 0];
        $metrics = $isReady ? $this->getSummaryMetrics() : [
            'total_trips' => 0,
            'total_records' => 0,
            'total_km' => 0,
            'total_km_loaded' => 0,
            'total_km_empty' => 0,
            'avg_duration' => null,
            'count_waiting_22h' => 0,
            'total_payment' => 0,
        ];
        $showCharges = $this->activeTab === 'external';
        $chargeCounts = $showCharges ? ($isReady ? $this->getChargeStatusCounts() : ['locked' => 0, 'missing' => 0]) : null;

        return [
            'loaded' => $isReady,
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
