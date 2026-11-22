<?php

namespace App\Filament\Resources\Vehicles\Pages;

use App\Filament\Forms\Components\PillFilter;
use App\Filament\Resources\Vehicles\VehicleResource;
use App\Models\Area;
use App\Models\Vehicle;
use App\Services\Export\SimpleTableExcelExporter;
use App\Services\Km\VehicleKmReportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListVehicles extends ListRecords
{
    protected static string $resource = VehicleResource::class;

    protected string $view = 'filament.resources.vehicles.pages.list-vehicles';

    public array $placeVehicleCurrent = [];

    public ?string $activeStatusFilter = 'all';

    public ?string $activeTypeFilter = 'all';

    public ?string $activePlaceFilter = 'all';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Xuất Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn (): StreamedResponse => $this->exportVehiclesExcel()),
            CreateAction::make()
                ->label('Thêm xe')
                ->icon('heroicon-o-plus')
                ->modal()
                ->modalHeading('Thêm xe mới')
                ->modalDescription('Điền thông tin chi tiết về xe để thêm vào hệ thống quản lý.')
                ->modalWidth('3xl')
                ->extraAttributes([
                    'class' => 'text-white font-bold [&_.fi-icon]:text-white! bg-[#008fd5] cursor-pointer hover:bg-[#0077b3] transition-colors',
                ]),
        ];
    }

    /**
     * Xuất danh sách xe theo bộ lọc, tìm kiếm và sắp xếp đang chọn trên bảng.
     */
    public function exportVehiclesExcel(): StreamedResponse
    {
        $vehicles = $this->getFilteredSortedTableQuery()->with('driver')->get();
        $latestKm = app(VehicleKmReportService::class)->latestReadings($vehicles->modelKeys(), now());

        $rows = $vehicles->values()->map(fn (Vehicle $vehicle, int $index): array => [
            $index + 1,
            $vehicle->plate_number,
            $vehicle->type?->getLabel(),
            $vehicle->vehicle_type?->getLabel(),
            $vehicle->owner,
            $vehicle->make,
            $vehicle->model_year,
            $vehicle->load_capacity !== null ? (float) $vehicle->load_capacity : null,
            $vehicle->cargo_volume !== null ? (float) $vehicle->cargo_volume : null,
            $vehicle->door_count,
            $vehicle->status?->getLabel(),
            $vehicle->isRent() && filled($vehicle->external_driver_name) ? $vehicle->external_driver_name : $vehicle->driver?->name,
            $vehicle->current_mileage !== null ? (float) $vehicle->current_mileage : null,
            $vehicle->monthly_start_km !== null ? (float) $vehicle->monthly_start_km : null,
            $latestKm[$vehicle->id]['km'] ?? null,
            isset($latestKm[$vehicle->id]) ? $latestKm[$vehicle->id]['at']->format('d/m/Y H:i') : null,
            $vehicle->dangerous_goods_permit_expiry_date?->format('d/m/Y'),
        ]);

        return app(SimpleTableExcelExporter::class)->download(
            title: 'Danh sách phương tiện - xuất lúc '.now()->format('H:i d/m/Y'),
            headers: [
                'STT', 'Biển số', 'Quản lý xe', 'Kiểu xe', 'Chủ xe', 'Hiệu xe', 'Năm SX', 'Tải trọng (tấn)', 'Số khối (m³)',
                'Số cửa', 'Trạng thái', 'Tài xế', 'Số km hiện tại', 'Km đầu chu kỳ (23 hàng tháng)',
                'Km mốc chuyến gần nhất', 'Thời điểm mốc km gần nhất', 'Hạn GP hàng nguy hiểm',
            ],
            rows: $rows,
            filename: 'Danh-sach-phuong-tien-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public array $vehicleStatusFilters = [
        'all' => [
            'color' => 'bg-blue-600',
            'icon' => 'heroicon-o-squares-2x2',
            'label' => 'Tất cả',
        ],
        'on' => [
            'color' => 'bg-emerald-500',
            'icon' => 'heroicon-o-check-circle',
            'label' => 'Sẵn sàng',
        ],
        'off' => [
            'color' => 'bg-rose-500',
            'icon' => 'heroicon-o-x-circle',
            'label' => 'Không hoạt động',
        ],
        'running' => [
            'color' => 'bg-blue-500',
            'icon' => 'heroicon-o-truck',
            'label' => 'Đang chạy',
        ],
        'bdsc' => [
            'color' => 'bg-amber-500',
            'icon' => 'heroicon-o-wrench-screwdriver',
            'label' => 'Bảo dưỡng sửa chữa',
        ],
    ];

    public array $vehicleTypes = [
        'all' => [
            'color' => 'bg-blue-600',
            'icon' => '',
            'label' => 'Tất cả',
        ],
        'company' => [
            'color' => 'bg-blue-500',
            'icon' => 'heroicon-o-home-modern',
            'label' => 'Xe công ty',
        ],
        'rent' => [
            'color' => 'bg-amber-500',
            'icon' => 'heroicon-o-currency-dollar',
            'label' => 'Xe thuê',
        ],
    ];

    public function mount(): void
    {
        parent::mount();

        $this->placeVehicleCurrent = Area::query()
            ->orderBy('sort_order')
            ->pluck('code', 'code')
            ->toArray();
    }

    public function getSubheading(): ?string
    {
        return '6 xe · 4 xe công ty · 2 xe thuê · Quản lý ON/OFF, tình trạng, đăng kiểm, BDSC';
    }

    public function filterStatus(string $status): void
    {
        $this->activeStatusFilter = $status;

        $this->resetPage();
    }

    public function filterType(string $type): void
    {
        $this->activeTypeFilter = $type;

        $this->resetPage();
    }

    public function filterPlace(string $place): void
    {
        $this->activePlaceFilter = $place;

        $this->resetPage();
    }

    protected function getForms(): array
    {
        return [
            'filtersForm',
        ];
    }

    public function filtersForm(Schema $form): Schema
    {
        return $form
            ->components([
                PillFilter::make('activeStatusFilter')
                    ->options($this->vehicleStatusFilters)
                    ->activeValue(fn ($livewire) => $livewire->activeStatusFilter)
                    ->clickAction('filterStatus'),

                PillFilter::make('activeTypeFilter')
                    ->options(fn () => collect($this->vehicleTypes)->except('all')->toArray())
                    ->activeValue(fn ($livewire) => $livewire->activeTypeFilter)
                    ->clickAction('filterType'),

                PillFilter::make('activePlaceFilter')
                    ->options(fn () => ['all' => 'Tất cả điểm'] + $this->placeVehicleCurrent)
                    ->activeValue(fn ($livewire) => $livewire->activePlaceFilter)
                    ->clickAction('filterPlace'),
            ]);
    }

    protected function getTableQuery(): Builder
    {
        return VehicleResource::getEloquentQuery()
            ->when(
                $this->activeStatusFilter !== 'all',
                fn (Builder $query): Builder => $query->where('status', $this->activeStatusFilter),
            )
            ->when(
                $this->activeTypeFilter !== 'all',
                fn (Builder $query): Builder => $query->where('type', $this->activeTypeFilter),
            )
            ->when(
                $this->activePlaceFilter !== 'all',
                fn (Builder $query): Builder => $query->whereHas(
                    'orders',
                    fn (Builder $orderQuery): Builder => $orderQuery->whereHas(
                        'area',
                        fn (Builder $categoryQuery): Builder => $categoryQuery->where('code', $this->activePlaceFilter),
                    ),
                ),
            )
            ->with([
                'driver',
                'latestMaintenance',
            ]);
    }
}
