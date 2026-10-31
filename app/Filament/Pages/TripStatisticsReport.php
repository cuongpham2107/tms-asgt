<?php

namespace App\Filament\Pages;

use App\Enums\CargoType;
use App\Enums\FreightRateType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SurchargeType;
use App\Enums\TripStatus;
use App\Filament\Resources\FreightRates\Schemas\FreightRateForm;
use App\Models\Customer;
use App\Models\FeeType;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\Trip;
use App\Services\Billing\OrderChargeCalculator;
use App\Services\Export\TripExcelExportService;
use App\Services\Import\OrderPackagesImporter;
use BackedEnum;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class TripStatisticsReport extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Thống kê';

    protected static ?string $title = 'Bảng thống kê tổng hợp các chuyến đã phục vụ';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.trip-statistics-report';

    #[Url]
    public ?string $startDate = null;

    #[Url]
    public ?string $endDate = null;

    #[Url]
    public ?string $datePreset = 'thisMonth';

    #[Url]
    public string $activeTab = 'HHHK';

    #[Url]
    public string $serviceType = 'HHHK';

    #[Url]
    public ?int $customerId = null;

    #[Url]
    public string $vehicleOwner = 'all';

    #[Url]
    public ?string $search = null;

    #[Url]
    public int $perPage = 25;

    public int $page = 1;

    protected ?Collection $cachedBaseRows = null;

    public function mount(): void
    {
        if (blank($this->startDate)) {
            $this->startDate = now()->startOfMonth()->format('Y-m-d');
        }

        if (blank($this->endDate)) {
            $this->endDate = now()->format('Y-m-d');
        }

        $this->syncDatePreset();

        if (filled($this->activeTab) && in_array($this->activeTab, ['HHHK', 'external', 'empty', 'all'])) {
            $this->serviceType = $this->activeTab;
        } elseif (filled($this->serviceType) && in_array($this->serviceType, ['HHHK', 'external', 'empty', 'all'])) {
            $this->activeTab = $this->serviceType;
        } else {
            $this->activeTab = 'HHHK';
            $this->serviceType = 'HHHK';
        }
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->serviceType = $tab;
        $this->page = 1;
    }

    protected function getBaseRowsCacheKey(): string
    {
        $userId = Auth::id() ?? 'guest';
        $filterHash = md5(json_encode([
            $this->startDate,
            $this->endDate,
            $this->customerId,
            $this->vehicleOwner,
            $this->search,
        ]));

        return "trip_stats_rows_{$userId}_{$filterHash}";
    }

    public function clearBaseRowsCache(): void
    {
        $this->cachedBaseRows = null;
        Cache::forget($this->getBaseRowsCacheKey());
    }

    public function updatedActiveTab(): void
    {
        $this->serviceType = $this->activeTab;
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedStartDate(): void
    {
        $this->syncDatePreset();
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedEndDate(): void
    {
        $this->syncDatePreset();
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedServiceType(): void
    {
        $this->activeTab = $this->serviceType;
        $this->page = 1;
    }

    public function updatedCustomerId(): void
    {
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedVehicleOwner(): void
    {
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->page = 1;
    }

    /**
     * Các hàm phân trang mà <x-filament::pagination> gọi (cùng chữ ký với WithPagination của Livewire).
     */
    public function gotoPage(int $page, string $pageName = 'page'): void
    {
        $this->page = max(1, $page);
    }

    public function previousPage(string $pageName = 'page'): void
    {
        $this->gotoPage($this->page - 1);
    }

    public function nextPage(string $pageName = 'page'): void
    {
        $this->gotoPage($this->page + 1);
    }

    public function setDatePreset(string $preset): void
    {
        $this->datePreset = $preset;

        match ($preset) {
            'today' => [
                $this->startDate = now()->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'yesterday' => [
                $this->startDate = now()->subDay()->format('Y-m-d'),
                $this->endDate = now()->subDay()->format('Y-m-d'),
            ],
            'last7days' => [
                $this->startDate = now()->subDays(6)->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'thisMonth' => [
                $this->startDate = now()->startOfMonth()->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'lastMonth' => [
                $this->startDate = now()->subMonth()->startOfMonth()->format('Y-m-d'),
                $this->endDate = now()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
            default => null,
        };

        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function syncDatePreset(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $this->datePreset = match (true) {
            $this->startDate === $today && $this->endDate === $today => 'today',
            $this->startDate === $yesterday && $this->endDate === $yesterday => 'yesterday',
            $this->startDate === now()->subDays(6)->format('Y-m-d') && $this->endDate === $today => 'last7days',
            $this->startDate === now()->startOfMonth()->format('Y-m-d') && $this->endDate === $today => 'thisMonth',
            $this->startDate === now()->subMonth()->startOfMonth()->format('Y-m-d') && $this->endDate === now()->subMonth()->endOfMonth()->format('Y-m-d') => 'lastMonth',
            default => null,
        };
    }

    public function isDatePresetActive(string $preset): bool
    {
        if ($this->datePreset === $preset) {
            return true;
        }

        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        return match ($preset) {
            'today' => $this->startDate === $today && $this->endDate === $today,
            'yesterday' => $this->startDate === $yesterday && $this->endDate === $yesterday,
            'last7days' => $this->startDate === now()->subDays(6)->format('Y-m-d') && $this->endDate === $today,
            'thisMonth' => $this->startDate === now()->startOfMonth()->format('Y-m-d') && $this->endDate === $today,
            'lastMonth' => $this->startDate === now()->subMonth()->startOfMonth()->format('Y-m-d') && $this->endDate === now()->subMonth()->endOfMonth()->format('Y-m-d'),
            default => false,
        };
    }

    private const DATE_PRESETS = [
        'today' => 'Hôm nay',
        'yesterday' => 'Hôm qua',
        'last7days' => '7 ngày qua',
        'thisMonth' => 'Tháng này',
        'lastMonth' => 'Tháng trước',
    ];

    public function getSubheading(): string
    {
        $from = Carbon::parse($this->startDate)->format('d/m/Y');
        $to = Carbon::parse($this->endDate)->format('d/m/Y');

        return $from === $to ? "Ngày {$from}" : "Từ {$from} đến {$to}";
    }

    /**
     * Cùng bố cục với các page Thống kê tài xế / Kiểm soát xe: khoảng thời gian và công cụ nằm ở header.
     */
    protected function getHeaderActions(): array
    {
        $presetActions = collect(self::DATE_PRESETS)->map(fn (string $label, string $preset): Action => Action::make('preset_'.$preset)
            ->label($label)
            ->color(fn (): string => $this->isDatePresetActive($preset) ? 'primary' : 'gray')
            ->action(fn () => $this->setDatePreset($preset)));

        return [
            ...$presetActions->values()->all(),
            Action::make('pickRange')
                ->label('Chọn ngày')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color(fn (): string => $this->datePreset === null ? 'primary' : 'gray')
                ->modalWidth(Width::Medium)
                ->modalSubmitActionLabel('Xem')
                ->fillForm(fn (): array => ['startDate' => $this->startDate, 'endDate' => $this->endDate])
                ->schema([
                    DatePicker::make('startDate')->label('Từ ngày')->native(false)->displayFormat('d/m/Y')->required(),
                    DatePicker::make('endDate')->label('Đến ngày')->native(false)->displayFormat('d/m/Y')->afterOrEqual('startDate')->required(),
                ])
                ->action(function (array $data): void {
                    $this->startDate = Carbon::parse($data['startDate'])->format('Y-m-d');
                    $this->endDate = Carbon::parse($data['endDate'])->format('Y-m-d');
                    $this->syncDatePreset();
                    $this->clearBaseRowsCache();
                    $this->page = 1;
                }),
            $this->importPackagesAction(),
            Action::make('export')
                ->label('Xuất Excel (.xlsx)')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('success')
                ->action(fn (): StreamedResponse => $this->exportExcel()),
        ];
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->datePreset = 'thisMonth';
        $this->customerId = null;
        $this->vehicleOwner = 'all';
        $this->search = null;
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    /**
     * @return Collection<int, Trip>
     */
    public function getFilteredTrips(): Collection
    {
        $dateFrom = $this->startDate ? Carbon::parse($this->startDate)->startOfDay() : null;
        $dateTo = $this->endDate ? Carbon::parse($this->endDate)->endOfDay() : null;
        $search = trim((string) $this->search);

        $tripsQuery = Trip::query()
            ->where('trips.status', TripStatus::Completed)
            ->with([
                'vehicle',
                'driver',
                'orders.customer',
                'orders.area',
                'orders.pickupLocation',
                'orders.deliveryPoints.location',
                'orders.tripCheckpoints',
                'checkpoints',
                'startLocation',
                'endLocation',
            ])
            ->when($dateFrom || $dateTo, function (Builder $q) use ($dateFrom, $dateTo): Builder {
                if ($dateFrom && $dateTo) {
                    return $q->where(function (Builder $sub) use ($dateFrom, $dateTo): void {
                        $sub->whereBetween('trips.started_at', [$dateFrom, $dateTo])
                            ->orWhere(function (Builder $o) use ($dateFrom, $dateTo): void {
                                $o->whereNull('trips.started_at')
                                    ->whereBetween('trips.created_at', [$dateFrom, $dateTo]);
                            });
                    });
                }
                if ($dateFrom) {
                    return $q->where(function (Builder $sub) use ($dateFrom): void {
                        $sub->where('trips.started_at', '>=', $dateFrom)
                            ->orWhere(function (Builder $o) use ($dateFrom): void {
                                $o->whereNull('trips.started_at')
                                    ->where('trips.created_at', '>=', $dateFrom);
                            });
                    });
                }

                return $q->where(function (Builder $sub) use ($dateTo): void {
                    $sub->where('trips.started_at', '<=', $dateTo)
                        ->orWhere(function (Builder $o) use ($dateTo): void {
                            $o->whereNull('trips.started_at')
                                ->where('trips.created_at', '<=', $dateTo);
                        });
                });
            })
            ->when(filled($search), function (Builder $q) use ($search): Builder {
                return $q->where(function (Builder $sub) use ($search): void {
                    $sub->where('trips.trip_code', 'like', "%{$search}%")
                        ->orWhereHas('vehicle', fn ($v) => $v->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('driver', fn ($d) => $d->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('orders', function (Builder $o) use ($search): void {
                            $o->where('order_code', 'like', "%{$search}%")
                                ->orWhere('cargo_name', 'like', "%{$search}%")
                                ->orWhereHas('customer', fn ($c) => $c->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
                        });
                });
            })
            ->when($this->vehicleOwner !== 'all', function (Builder $q): Builder {
                return $q->whereHas('vehicle', fn ($v) => $v->where('type', $this->vehicleOwner));
            })
            ->when(filled($this->customerId), function (Builder $q): Builder {
                return $q->whereHas('orders', fn ($o) => $o->where('customer_id', $this->customerId));
            })
            ->orderByDesc('trips.started_at')
            ->orderByDesc('trips.created_at');

        $trips = $tripsQuery->get();

        // Lấy thêm các orders chưa gán trip (nếu có) đã hoàn thành
        $unassignedOrdersQuery = Order::query()
            ->whereNull('trip_id')
            ->where('status', OrderStatus::Completed)
            ->with([
                'customer',
                'area',
                'pickupLocation',
                'deliveryPoints.location',
                'tripCheckpoints',
            ])
            ->when($dateFrom || $dateTo, function (Builder $q) use ($dateFrom, $dateTo): Builder {
                if ($dateFrom && $dateTo) {
                    return $q->whereBetween('planned_loading_at', [$dateFrom, $dateTo]);
                }
                if ($dateFrom) {
                    return $q->where('planned_loading_at', '>=', $dateFrom);
                }

                return $q->where('planned_loading_at', '<=', $dateTo);
            })
            ->when(filled($search), function (Builder $q) use ($search): Builder {
                return $q->where(function (Builder $sub) use ($search): void {
                    $sub->where('order_code', 'like', "%{$search}%")
                        ->orWhere('cargo_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
                });
            })
            ->when(filled($this->customerId), fn ($q) => $q->where('customer_id', $this->customerId));

        $unassignedOrders = $unassignedOrdersQuery->get();

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $syntheticTrips = $exportService->convertOrdersToTripsCollection($unassignedOrders);

        return $trips->merge($syntheticTrips);
    }

    /**
     * Lấy toàn bộ dòng dữ liệu đã lọc theo ngày, khách hàng, xe và từ khóa
     * (chưa lọc theo loại hình / tab để tái sử dụng cho đếm số lượng từng tab).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getBaseFilteredRows(): Collection
    {
        if ($this->cachedBaseRows !== null) {
            return $this->cachedBaseRows;
        }

        $cacheKey = $this->getBaseRowsCacheKey();

        /** @var array<int, array<string, mixed>>|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $this->cachedBaseRows = collect($cached);
        }

        $trips = $this->getFilteredTrips();

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $rows = $exportService->getTripSummaryRows($trips);

        // 1. Lọc theo Khách hàng
        if (filled($this->customerId)) {
            $rows = $rows->filter(function (array $row): bool {
                return (string) ($row['customer_id'] ?? '') === (string) $this->customerId;
            })->values();
        }

        // 2. Lọc theo Quản lý xe (Xe công ty / Xe thuê)
        if ($this->vehicleOwner !== 'all') {
            $rows = $rows->filter(function (array $row): bool {
                $targetOwner = $this->vehicleOwner === 'company' ? 'Xe công ty' : 'Xe thuê';

                return ($row['vehicle_owner'] ?? '') === $targetOwner;
            })->values();
        }

        // 3. Lọc theo Từ khóa tìm kiếm
        if (filled($this->search)) {
            $query = mb_strtolower(trim($this->search));
            $rows = $rows->filter(function (array $row) use ($query): bool {
                $searchable = implode(' ', [
                    $row['code'] ?? '',
                    $row['plate_number'] ?? '',
                    $row['driver_name'] ?? '',
                    $row['customer'] ?? '',
                    $row['warehouse'] ?? '',
                    $row['journey'] ?? '',
                    $row['note'] ?? '',
                    $row['cargo_type'] ?? '',
                ]);

                return str_contains(mb_strtolower($searchable), $query);
            })->values();
        }

        // Chuẩn hóa ngày giờ sang chuỗi ISO / scalar để serialize an toàn vào Cache
        $serializedRows = $rows->map(function (array $row): array {
            $row['date'] = $row['date'] instanceof Carbon ? $row['date']->toDateTimeString() : ($row['date'] ? (string) $row['date'] : null);
            $row['time_a'] = $row['time_a'] instanceof Carbon ? $row['time_a']->toDateTimeString() : ($row['time_a'] ? (string) $row['time_a'] : null);
            $row['time_b'] = $row['time_b'] instanceof Carbon ? $row['time_b']->toDateTimeString() : ($row['time_b'] ? (string) $row['time_b'] : null);
            $row['time_c'] = $row['time_c'] instanceof Carbon ? $row['time_c']->toDateTimeString() : ($row['time_c'] ? (string) $row['time_c'] : null);
            $row['time_d'] = $row['time_d'] instanceof Carbon ? $row['time_d']->toDateTimeString() : ($row['time_d'] ? (string) $row['time_d'] : null);

            return $row;
        });

        Cache::put($cacheKey, $serializedRows->all(), 120);

        return $this->cachedBaseRows = $serializedRows;
    }

    /**
     * Số lượng bản ghi cho 3 tab
     *
     * @return array<string, int>
     */
    public function getTabCounts(): array
    {
        $baseRows = $this->getBaseFilteredRows();

        $tripCount = fn (string $serviceType): int => $baseRows->where('service_type', $serviceType)->pluck('trip_id')->unique()->count();

        return [
            'HHHK' => $tripCount('HHHK'),
            'external' => $tripCount('Hàng ngoài'),
            'empty' => $tripCount('Xe không hàng'),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getAllRows(): Collection
    {
        $rows = $this->getBaseFilteredRows();
        $target = $this->activeTab ?: $this->serviceType;

        if ($target === 'all') {
            return $rows;
        }

        return match ($target) {
            'HHHK' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'HHHK')->values(),
            'external' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'Hàng ngoài')->values(),
            'empty' => $rows->filter(fn (array $row): bool => $row['service_type'] === 'Xe không hàng')->values(),
            default => $rows,
        };
    }

    /**
     * Mỗi chuyến một dòng, kèm các dòng đơn trong 'orders'.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getTripRows(): Collection
    {
        return app(TripExcelExportService::class)->groupRowsByTrip($this->getAllRows());
    }

    public function getActiveTabTitle(): string
    {
        return match ($this->activeTab) {
            'HHHK' => 'HHHK (Hàng không)',
            'external' => 'Hàng ngoài',
            'empty' => 'Xe không hàng',
            default => 'Tất cả loại hình',
        };
    }

    /**
     * Định dạng thời gian từ số phút sang định dạng giờ và phút (ví dụ: 193h 29p, 45p, 2h).
     */
    public function formatDuration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes <= 0) {
            return '0p';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours}h {$mins}p";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        return "{$mins}p";
    }

    /**
     * Tooltip hiển thị đầy đủ chi tiết thời gian (ví dụ: "193 giờ 29 phút (11,609 phút)").
     */
    public function formatDurationTooltip(?int $minutes): string
    {
        if ($minutes === null) {
            return 'Chưa có dữ liệu';
        }

        if ($minutes <= 0) {
            return '0 phút';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $formattedTotal = number_format($minutes);

        if ($hours > 0 && $mins > 0) {
            return "{$hours} giờ {$mins} phút ({$formattedTotal} phút)";
        }

        if ($hours > 0) {
            return "{$hours} giờ ({$formattedTotal} phút)";
        }

        return "{$mins} phút";
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummaryMetrics(): array
    {
        $rows = $this->getAllRows();
        $tripRows = $this->getTripRows();

        $totalRecords = $rows->whereNotNull('order_id')->count();
        $totalTrips = $tripRows->count();
        $totalKmLoaded = $tripRows->sum('km_loaded');
        $totalKmEmpty = $tripRows->sum('km_empty');
        $totalPcs = $rows->sum('pcs');
        $totalGw = $rows->sum('gw');
        $totalChargeableWeight = $rows->sum('chargeable_weight');

        $rowsWithDuration = $tripRows->whereNotNull('time_total_trip');
        $avgDuration = $rowsWithDuration->isNotEmpty() ? (int) round($rowsWithDuration->avg('time_total_trip')) : 0;
        $countWaiting22h = $tripRows->filter(fn (array $r) => ($r['time_waiting_22h'] ?? 0) > 0)->count();

        return [
            'total_records' => $totalRecords,
            'total_trips' => $totalTrips,
            'total_km_loaded' => $totalKmLoaded,
            'total_km_empty' => $totalKmEmpty,
            'total_km' => $totalKmLoaded + $totalKmEmpty,
            'total_pcs' => $totalPcs,
            'total_gw' => $totalGw,
            'total_chargeable_weight' => $totalChargeableWeight,
            'avg_duration' => $avgDuration,
            'count_waiting_22h' => $countWaiting22h,
            'total_payment' => $this->activeTab === 'external'
                ? (float) OrderCharge::whereIn('order_id', $rows->pluck('order_id')->filter())->sum('total')
                : 0.0,
        ];
    }

    public function getPaginator(): LengthAwarePaginatorContract
    {
        $rows = $this->getTripRows();
        $total = $rows->count();
        $perPage = max(10, $this->perPage);
        $currentPage = max(1, $this->page);

        $items = $rows->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return array<int, string>
     */
    public function getCustomerOptions(): array
    {
        return Cache::remember('trip_stats_customer_options', 300, function (): array {
            return Customer::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->pluck('code', 'id')
                ->toArray();
        });
    }

    public function updateOrderMetric(int $orderId, string $field, mixed $value): void
    {
        if (! in_array($field, ['total_packages', 'total_weight'], true)) {
            return;
        }

        /** @var Order|null $order */
        $order = Order::find($orderId);
        if (! $order) {
            Notification::make()
                ->title('Không tìm thấy đơn hàng')
                ->danger()
                ->send();

            return;
        }

        if ($field === 'total_packages') {
            $cleanVal = ($value === '' || $value === null) ? null : max(0, (int) $value);
            $order->total_packages = $cleanVal;
            $label = 'PCS';
            $displayVal = $cleanVal !== null ? number_format($cleanVal) : '0';
        } else {
            $cleanVal = ($value === '' || $value === null) ? null : max(0, round((float) $value, 2));
            $order->total_weight = $cleanVal;
            $label = 'GW';
            $displayVal = $cleanVal !== null ? number_format($cleanVal, 2) : '0';
        }

        $order->save();

        $this->clearBaseRowsCache();

        Notification::make()
            ->title("Đã cập nhật {$label}: {$displayVal}")
            ->body($order->order_code ? "Đơn hàng: {$order->order_code}" : null)
            ->success()
            ->send();
    }

    /**
     * Cước của các đơn trên trang hiện tại, theo order_id.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return Collection<int, OrderCharge>
     */
    public function getChargesForRows(iterable $rows): Collection
    {
        $orderIds = collect($rows)->flatMap(fn (array $row): array => $row['orders'] ?? [$row])->pluck('order_id')->filter()->unique();

        if ($orderIds->isEmpty()) {
            return collect();
        }

        return OrderCharge::query()
            ->with('fees')
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy('order_id');
    }

    public function importPackagesAction(): Action
    {
        return Action::make('importPackages')
            ->label('Import cân kiện')
            ->icon('heroicon-m-arrow-up-tray')
            ->color('info')
            ->visible(fn (): bool => $this->activeTab === 'HHHK')
            ->modalHeading('Import PCS / GW cho đơn HHHK')
            ->modalDescription('File theo mẫu sheet "1.1 Mẫu Upload dữ liệu cân kiện": cột B Mã đơn hàng, cột F PCS, cột G GW. Ô PCS / GW để trống thì giữ nguyên số cũ.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('File Excel')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var TemporaryUploadedFile $file */
                $file = $data['file'];
                $result = app(OrderPackagesImporter::class)->import($file->getRealPath());

                $this->clearBaseRowsCache();

                $notFound = $result['not_found'];
                Notification::make()
                    ->title("Đã cập nhật PCS / GW cho {$result['updated']} đơn")
                    ->body(collect([
                        $result['unchanged'] > 0 ? "{$result['unchanged']} đơn không thay đổi." : null,
                        $notFound !== [] ? 'Không tìm thấy mã đơn: '.implode(', ', array_slice($notFound, 0, 20)).(count($notFound) > 20 ? '…' : '') : null,
                    ])->filter()->implode(' '))
                    ->status($notFound === [] ? 'success' : 'warning')
                    ->persistent($notFound !== [])
                    ->send();
            });
    }

    public function editChargeAction(): Action
    {
        return Action::make('editCharge')
            ->label('Sửa cước')
            ->icon('heroicon-m-pencil-square')
            ->modalHeading(fn (array $arguments): string => 'Cước đơn '.(Order::find($arguments['orderId'] ?? null)?->order_code ?? ''))
            ->modalDescription(fn (array $arguments): ?string => $this->findCharge($arguments)?->isLocked()
                ? 'Dòng đã xuất bảng kê nên đang bị khoá. Mở khoá để sửa.'
                : 'Đổi loại hình / trọng tải tính cước xong hãy bấm "Tính lại" để tra lại đơn giá.')
            ->modalWidth(Width::MaxContent)
            ->modalSubmitActionLabel('Lưu')
            ->fillForm(function (array $arguments): array {
                $charge = $this->resolveCharge((int) ($arguments['orderId'] ?? 0));

                return [
                    ...$charge->only(self::EDITABLE_CHARGE_FIELDS),
                    // Số thuần (bỏ ".00" của cột decimal) để mask tiền hiển thị "1,300,000".
                    'base_price' => $charge->base_price === null ? null : (float) $charge->base_price,
                    'waiting_fee' => (float) $charge->waiting_fee,
                    'fees' => $this->feeRowsForForm($charge),
                ];
            })
            ->schema(fn (array $arguments): array => $this->chargeFormSchema($this->findCharge($arguments), (int) ($arguments['orderId'] ?? 0)))
            ->action(function (array $data, array $arguments): void {
                $charge = $this->resolveCharge((int) ($arguments['orderId'] ?? 0));

                if ($charge->isLocked()) {
                    Notification::make()->title('Dòng cước đã khoá, không thể sửa')->warning()->send();

                    return;
                }

                $charge->fill(Arr::only($data, self::EDITABLE_CHARGE_FIELDS));
                $charge->delivery_notes = collect($data['delivery_notes'] ?? [])
                    ->map(fn (array $note): array => [
                        'number' => filled($note['number'] ?? null) ? trim($note['number']) : null,
                        'actual_weight' => is_numeric($note['actual_weight'] ?? null) ? (float) $note['actual_weight'] : null,
                        'chargeable_weight' => is_numeric($note['chargeable_weight'] ?? null) ? (float) $note['chargeable_weight'] : null,
                    ])
                    ->values()
                    ->all();
                foreach (['return_trip_percent', 'fuel_adjustment_percent', 'vat_percent', 'waiting_minutes', 'waiting_fee'] as $field) {
                    $charge->{$field} ??= 0;
                }
                $charge->rate_type ??= '';
                $charge->surcharge_type ??= '';
                $charge->save();

                $charge->fees()->delete();
                // Form liệt kê sẵn mọi loại phí với số tiền 0 — chỉ lưu những phí có tiền.
                $charge->fees()->createMany(collect($data['fees'] ?? [])
                    ->filter(fn (array $fee): bool => filled($fee['fee_type_id'] ?? null) && (float) ($fee['amount'] ?? 0) > 0)
                    ->map(fn (array $fee): array => ['fee_type_id' => $fee['fee_type_id'], 'amount' => (float) $fee['amount']])
                    ->all());

                $charge->recalculateTotals()->save();

                Notification::make()->title('Đã lưu cước')->success()->send();
            });
    }

    public function recalculateCharge(int $orderId): void
    {
        $order = Order::find($orderId);

        if ($order === null || $order->type !== OrderType::External) {
            return;
        }

        $charge = app(OrderChargeCalculator::class)->calculate($order);

        if ($charge->isLocked()) {
            Notification::make()->title('Dòng cước đã khoá, không tính lại')->warning()->send();

            return;
        }

        Notification::make()
            ->title($charge->base_price === null ? 'Chưa tìm thấy giá phù hợp' : 'Đã tính lại cước')
            ->body($order->order_code)
            ->status($charge->base_price === null ? 'warning' : 'success')
            ->send();
    }

    public function unlockCharge(int $orderId): void
    {
        OrderCharge::where('order_id', $orderId)->update(['exported_at' => null, 'exported_by' => null]);

        Notification::make()->title('Đã mở khoá dòng cước')->success()->send();
    }

    /**
     * @var list<string>
     */
    private const EDITABLE_CHARGE_FIELDS = [
        'freight_rate_id',
        'price_code',
        'rate_type',
        'surcharge_type',
        'chargeable_tonnage',
        'is_return_trip',
        'return_trip_percent',
        'delivery_notes',
        'base_price',
        'fuel_adjustment_percent',
        'waiting_minutes',
        'waiting_fee',
        'vat_percent',
        'note',
    ];

    /**
     * Ô nhập tiền: hiển thị phân cách hàng nghìn, lưu số thuần.
     */
    public static function moneyInput(string $name): TextInput
    {
        return TextInput::make($name)
            ->mask(RawJs::make('$money($input)'))
            ->stripCharacters(',')
            ->numeric()
            ->minValue(0)
            ->suffix('đ');
    }

    /**
     * Mọi loại phí đang dùng (theo thứ tự danh mục) kèm số tiền đã nhập, chưa nhập thì 0.
     *
     * @return list<array{fee_type_id: int, amount: float}>
     */
    private function feeRowsForForm(OrderCharge $charge): array
    {
        $amounts = $charge->fees->pluck('amount', 'fee_type_id');

        return FeeType::query()
            ->where('is_active', true)
            ->orWhereIn('id', $amounts->keys())
            ->orderBy('sort')
            ->get()
            ->map(fn (FeeType $feeType): array => [
                'fee_type_id' => $feeType->id,
                'amount' => (float) ($amounts->get($feeType->id) ?? 0),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function findCharge(array $arguments): ?OrderCharge
    {
        return OrderCharge::firstWhere('order_id', $arguments['orderId'] ?? null);
    }

    private function resolveCharge(int $orderId): OrderCharge
    {
        $order = Order::where('type', OrderType::External)->findOrFail($orderId);

        return $order->charge ?? app(OrderChargeCalculator::class)->calculate($order);
    }

    /**
     * @return array<int, mixed>
     */
    /**
     * Form sửa cước: bên trái các trường nhập, bên phải xem chuyến + bảng tính giá (cập nhật theo form).
     *
     * @return array<int, mixed>
     */
    /**
     * Tuyến chưa có đơn giá → mở đúng form "Thêm đơn giá vận chuyển", điền sẵn theo đơn (khách hàng, khu vực đi/đến,
     * loại hàng, loại hình, bậc tải). Lưu xong thì gán khu vực cho địa điểm chưa có khu vực và tra lại đơn giá trên form.
     * Tuyến đã có dòng giá cùng khoá thì gộp bậc tải vào dòng đó thay vì tạo trùng.
     */
    private function addFreightRateAction(?OrderCharge $charge, Closure $refreshPrice): Action
    {
        $order = $charge?->order?->loadMissing(['pickupLocation', 'deliveryPoints.location']);
        $pickup = $order?->pickupLocation;
        $delivery = $order?->deliveryPoints->sortBy('sequence')->first()?->location;

        return Action::make('addFreightRate')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->tooltip('Thêm đơn giá vào bảng giá')
            ->visible(fn (Get $get): bool => $charge !== null && ! $charge->isLocked() && blank($get('freight_rate_id')))
            ->model(FreightRate::class)
            ->modalHeading('Thêm đơn giá vận chuyển')
            ->modalDescription(collect([$pickup, $delivery])->filter(fn (?Location $location): bool => $location !== null && $location->billing_zone_id === null)
                ->map(fn (Location $location): string => "Địa điểm {$location->code} chưa thuộc khu vực nào — khu vực chọn bên dưới sẽ được gán luôn.")
                ->implode(' ') ?: null)
            ->modalSubmitActionLabel('Lưu đơn giá')
            ->schema(fn (Schema $schema): Schema => FreightRateForm::configure($schema))
            ->fillForm(fn (Get $get): array => [
                'customer_id' => $charge?->customer_id,
                'origin_zone_id' => $pickup?->billing_zone_id,
                'destination_zone_id' => $delivery?->billing_zone_id,
                'cargo_type' => $charge?->cargo_type ?? CargoType::Gcr,
                'rate_type' => $get('rate_type'),
                'prices' => filled($get('chargeable_tonnage')) ? [FreightRate::tonnageKey($get('chargeable_tonnage')) => null] : [],
                'notes' => 'Thêm từ modal cước đơn '.($order?->order_code ?? ''),
            ])
            ->action(function (array $data, Get $get, Set $set) use ($pickup, $delivery, $refreshPrice): void {
                $key = Arr::only($data, ['customer_id', 'origin_zone_id', 'destination_zone_id', 'cargo_type', 'rate_type', 'vehicle_type', 'effective_from', 'effective_to']);
                $rate = FreightRate::query()->firstOrNew($key);
                $rate->fill(Arr::except($data, ['prices']));
                $rate->prices = array_replace($rate->prices ?? [], $data['prices'] ?? []);
                $rate->save();

                foreach ([[$pickup, $data['origin_zone_id']], [$delivery, $data['destination_zone_id']]] as [$location, $zoneId]) {
                    if ($location !== null && $location->billing_zone_id === null) {
                        $location->update(['billing_zone_id' => $zoneId]);
                    }
                }

                $set('rate_type', $rate->rate_type);
                $refreshPrice($get, $set);

                Notification::make()->title('Đã thêm đơn giá vào bảng giá')->success()->send();
            });
    }

    private function chargeFormSchema(?OrderCharge $charge, int $orderId): array
    {
        $money = fn (string $name, string $label): TextInput => self::moneyInput($name)->label($label);

        // Đổi loại hình / trọng tải / quay đầu → tra lại đơn giá ngay trên form (chưa lưu).
        $refreshPrice = function (Get $get, Set $set) use ($charge): void {
            if ($charge === null || $charge->isLocked() || $charge->order === null) {
                return;
            }

            $draft = app(OrderChargeCalculator::class)->previewFreightRate($charge->order, $charge, [
                'rate_type' => $get('rate_type'),
                'chargeable_tonnage' => $get('chargeable_tonnage'),
                'is_return_trip' => $get('is_return_trip'),
                'return_trip_percent' => $get('return_trip_percent'),
            ]);

            $set('base_price', $draft->base_price);
            $set('freight_rate_id', $draft->freight_rate_id);
            $set('price_code', $draft->price_code);
        };

        return [
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 2])
                    ->disabled(fn (): bool => (bool) $charge?->isLocked())
                    ->schema([
                        Section::make('Cách tính cước')
                            ->description('Đổi các ô này thì đơn giá được tra lại theo bảng giá.')
                            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                            ->columns(3)
                            ->compact()
                            ->schema([
                                FreightRateType::select('rate_type')->label('Loại hình tính cước')
                                    ->live()->afterStateUpdated($refreshPrice),
                                SurchargeType::select('surcharge_type')->label('Loại hình tính phụ phí'),
                                TextInput::make('chargeable_tonnage')->label('Trọng tải tính cước')->numeric()->minValue(0)->suffix('tấn')
                                    ->live(debounce: 500)->afterStateUpdated($refreshPrice),
                                Toggle::make('is_return_trip')->label('Chuyến quay đầu')->inline(false)
                                    ->live()->afterStateUpdated($refreshPrice),
                                TextInput::make('return_trip_percent')->label('% đơn giá khi quay đầu')->numeric()->minValue(0)->suffix('%')
                                    ->visible(fn (Get $get): bool => (bool) $get('is_return_trip'))
                                    ->live(debounce: 500)->afterStateUpdated($refreshPrice),
                                Hidden::make('freight_rate_id'),
                                Hidden::make('price_code'),
                            ]),
                        Section::make('Phiếu giao hàng & trọng lượng')
                            ->description('Trọng lượng vượt mức khách trả thì tách thành nhiều phiếu — mỗi phiếu là 1 dòng trong bảng kê.')
                            ->icon(Heroicon::OutlinedDocumentDuplicate)
                            ->compact()
                            ->schema([
                                Repeater::make('delivery_notes')
                                    ->hiddenLabel()
                                    ->table([
                                        TableColumn::make('Số phiếu giao hàng'),
                                        TableColumn::make('Trọng lượng hàng thực tế (kg)'),
                                        TableColumn::make('Trọng lượng tính tiền (kg)'),
                                    ])
                                    ->compact()
                                    ->defaultItems(1)
                                    ->addActionLabel('Tách phiếu')
                                    ->schema([
                                        TextInput::make('number'),
                                        TextInput::make('actual_weight')->numeric()->minValue(0),
                                        TextInput::make('chargeable_weight')->numeric()->minValue(0),
                                    ]),
                            ]),
                        Section::make('Đơn giá & giờ chờ')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->columns(3)
                            ->compact()
                            ->schema([
                                $money('base_price', 'Đơn giá vận chuyển gốc')->live(debounce: 500)
                                    ->suffixAction($this->addFreightRateAction($charge, $refreshPrice)),
                                TextInput::make('fuel_adjustment_percent')->label('Tỷ lệ tăng/giảm dầu')->numeric()->suffix('%')->live(debounce: 500),
                                TextInput::make('vat_percent')->label('VAT')->numeric()->minValue(0)->suffix('%')->live(debounce: 500),
                                TextInput::make('waiting_minutes')->label('Thời gian chờ')->hint('Đóng hàng + hạ hàng')->numeric()->minValue(0)->suffix('phút'),
                                $money('waiting_fee', 'Chi phí giờ chờ')->live(debounce: 500),
                            ]),
                        Section::make('Phí khác & ghi chú')
                            ->icon(Heroicon::OutlinedReceiptPercent)
                            ->compact()
                            ->schema([
                                Repeater::make('fees')
                                    ->hiddenLabel()
                                    ->table([
                                        TableColumn::make('Loại phí')->markAsRequired(),
                                        TableColumn::make('Số tiền (đ)')->markAsRequired(),
                                    ])
                                    ->compact()
                                    ->defaultItems(0)
                                    ->addActionLabel('Thêm phí')
                                    ->live()
                                    ->schema([
                                        Select::make('fee_type_id')
                                            ->options(fn (): array => FeeType::where('is_active', true)->orderBy('sort')->pluck('name', 'id')->all())
                                            ->distinct()
                                            ->required(),
                                        self::moneyInput('amount')->required()->live(debounce: 500),
                                    ]),
                                Textarea::make('note')->label('Ghi chú cước')->rows(2),
                            ]),
                    ]),
                Group::make($this->chargeSummarySchema($charge, $orderId))->columnSpan(['lg' => 1]),
            ]),
        ];
    }

    /**
     * Cột phải: thông tin chuyến, các mốc thời gian và bảng tính giá theo số đang nhập.
     *
     * @return array<int, mixed>
     */
    private function chargeSummarySchema(?OrderCharge $charge, int $orderId): array
    {
        $order = Order::find($orderId);
        $row = $order === null
            ? []
            : (app(TripExcelExportService::class)->getTripSummaryRowsFromOrders(Order::query()->whereKey($orderId))->firstWhere('order_id', $orderId) ?? []);

        $cargoType = $order?->cargo_type ?? CargoType::tryFrom((string) $charge?->cargo_type);
        $time = fn (mixed $value): string => $value ? Carbon::parse($value)->format('H:i d/m/Y') : '—';
        $duration = fn (?int $minutes): string => $minutes === null ? '—' : $this->formatDuration($minutes);
        $money = fn (?float $value): string => $value === null ? '—' : number_format($value, 0, ',', '.').' đ';
        $entry = fn (string $name, string $label, Heroicon $icon, Closure|string $state, string $iconColor = 'gray'): TextEntry => TextEntry::make($name)
            ->label($label)
            ->inlineLabel()
            ->icon($icon)
            ->iconColor($iconColor)
            ->state($state);
        // Ô tiền có mask "1,300,000" nên state trên form còn dấu phẩy.
        $number = fn (mixed $value): ?float => is_numeric($clean = str_replace(',', '', (string) $value)) ? (float) $clean : null;
        $totals = fn (Get $get): array => OrderCharge::computeTotals(
            basePrice: $number($get('base_price')),
            fuelPercent: (float) $get('fuel_adjustment_percent'),
            waitingFee: (float) $number($get('waiting_fee')),
            otherFees: (float) collect($get('fees') ?? [])->sum(fn (array $fee): float => (float) $number($fee['amount'] ?? 0)),
            vatPercent: (float) $get('vat_percent'),
        );
        $percent = fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').'%';

        return [
            Section::make('Chuyến')
                ->icon(Heroicon::OutlinedTruck)
                ->iconColor('primary')
                ->compact()
                ->schema([
                    $entry('summary_vehicle', 'Biển số', Heroicon::OutlinedIdentification, $row['plate_number'] ?? '—')
                        ->badge()
                        ->color('gray')
                        ->fontFamily(FontFamily::Mono)
                        ->weight(FontWeight::Bold),
                    $entry('summary_driver', 'Lái xe', Heroicon::OutlinedUser, $row['driver_name'] ?? '—')
                        ->weight(FontWeight::Bold),
                    $entry('summary_journey', 'Hành trình', Heroicon::OutlinedMap, $row['journey'] ?? '—')
                        ->weight(FontWeight::Medium),
                    $entry('summary_cargo_type', 'Loại hàng', Heroicon::OutlinedCube, $cargoType?->getLabel() ?? '—')
                        ->badge()
                        ->color($cargoType?->getColor() ?? 'gray'),
                ]),
            Section::make('Mốc thời gian')
                ->icon(Heroicon::OutlinedClock)
                ->iconColor('info')
                ->compact()
                ->schema([
                    $entry('summary_requested_at', 'Yêu cầu', Heroicon::OutlinedCalendarDays, $time($order?->planned_loading_at)),
                    $entry('summary_loading_at', 'Đóng hàng', Heroicon::OutlinedArrowDownTray, $time($row['time_a'] ?? null), 'info'),
                    $entry('summary_departed_at', 'Xe chạy', Heroicon::OutlinedTruck, $time($row['time_b'] ?? null), 'info'),
                    $entry('summary_arrived_at', 'Đến nơi', Heroicon::OutlinedMapPin, $time($row['time_c'] ?? null), 'info'),
                    $entry('summary_unloaded_at', 'Hạ hàng', Heroicon::OutlinedArrowUpTray, $time($row['time_d'] ?? null), 'info'),
                    Grid::make(3)->schema([
                        TextEntry::make('summary_loading_duration')->label('Đóng hàng')->state($duration($row['time_loading'] ?? null))->badge()->color('warning'),
                        TextEntry::make('summary_travel_duration')->label('Di chuyển')->state($duration($row['time_travel'] ?? null))->badge()->color('info'),
                        TextEntry::make('summary_unloading_duration')->label('Hạ hàng')->state($duration($row['time_unloading'] ?? null))->badge()->color('warning'),
                    ]),
                ]),
            Section::make('Tính giá')
                ->icon(Heroicon::OutlinedCalculator)
                ->iconColor('success')
                ->compact()
                ->schema([
                    $entry('summary_price_code', 'Mã giá', Heroicon::OutlinedHashtag, fn (Get $get): string => $get('price_code') ?: 'Chưa có giá')
                        ->badge()
                        ->color(fn (Get $get): string => $get('price_code') ? 'gray' : 'warning')
                        ->copyable(fn (Get $get): bool => (bool) $get('price_code')),
                    $entry('summary_base_price', 'Đơn giá gốc', Heroicon::OutlinedBanknotes, fn (Get $get): string => $money($number($get('base_price')))),
                    $entry('summary_adjusted_price', 'Sau dầu', Heroicon::OutlinedBeaker, fn (Get $get): string => $money($totals($get)['adjusted_price']))
                        ->helperText(fn (Get $get): string => 'Tăng/giảm dầu '.$percent($get('fuel_adjustment_percent')).', làm tròn nghìn'),
                    $entry('summary_waiting_fee', 'Giờ chờ', Heroicon::OutlinedClock, fn (Get $get): string => $money((float) $number($get('waiting_fee'))))
                        ->helperText(fn (Get $get): string => 'Chờ '.((int) $get('waiting_minutes')).' phút (trước khi trừ miễn phí)'),
                    $entry('summary_other_fees', 'Phí khác', Heroicon::OutlinedReceiptPercent, fn (Get $get): string => $money($totals($get)['other_fees_total'])),
                    $entry('summary_subtotal', 'Tổng', Heroicon::OutlinedRectangleStack, fn (Get $get): string => $money($totals($get)['subtotal']))
                        ->weight(FontWeight::SemiBold),
                    $entry('summary_vat', 'VAT', Heroicon::OutlinedDocumentText, fn (Get $get): string => $money($totals($get)['vat_amount']))
                        ->helperText(fn (Get $get): string => 'Thuế suất '.$percent($get('vat_percent'))),
                ]),
            Section::make('Thanh toán')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->iconColor('primary')
                ->compact()
                ->schema([
                    $entry('summary_total', 'Tổng thanh toán', Heroicon::OutlinedBanknotes, fn (Get $get): string => $money($totals($get)['total']), 'primary')
                        ->weight(FontWeight::Bold)
                        ->color('primary'),
                ]),
        ];
    }

    public function exportExcel(): StreamedResponse
    {
        $rows = $this->getAllRows();
        $trips = $this->getFilteredTrips();

        OrderCharge::query()
            ->whereIn('order_id', $rows->pluck('order_id')->filter())
            ->whereNull('exported_at')
            ->update(['exported_at' => now(), 'exported_by' => Auth::id()]);

        $tabSuffix = match ($this->activeTab) {
            'HHHK' => 'HHHK',
            'external' => 'Hang-ngoai',
            'empty' => 'Xe-khong-hang',
            default => 'Tong-hop',
        };

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $filename = "Bao-cao-thong-ke-{$tabSuffix}-".now()->format('Ymd-His').'.xlsx';

        return $exportService->exportFromRows($rows, $trips, $filename);
    }
}
