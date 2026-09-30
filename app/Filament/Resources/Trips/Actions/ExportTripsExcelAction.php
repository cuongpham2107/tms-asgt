<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Filament\Resources\Trips\Pages\ListTrips;
use App\Models\Area;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Export\TripExcelExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportTripsExcelAction
{
    public static function make(): Action
    {
        return Action::make('exportExcel')
            ->label('Xuất Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->modalHeading('Xuất Excel danh sách chuyến xe')
            ->modalDescription('Tùy chọn bộ lọc dữ liệu chuyến xe trước khi xuất file Excel.')
            ->modalSubmitActionLabel('Xuất dữ liệu')
            ->modalWidth(Width::TwoExtraLarge)
            ->fillForm(fn (ListTrips $livewire): array => [
                'date_from' => $livewire->dateFrom,
                'date_to' => $livewire->dateTo,
                'order_type' => $livewire->orderType ?? 'all',
                'status' => $livewire->activeStatusFilter ?? 'all',
                'vehicle_owner' => $livewire->vehicleOwner ?? 'all',
                'place' => $livewire->activePlaceFilter ?? 'all',
                'vehicle_id' => null,
                'driver_id' => null,
                'search' => $livewire->tripSearch,
            ])
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('date_from')
                        ->label('Từ ngày (ngày bắt đầu)')
                        ->displayFormat('d/m/Y')
                        ->native(false)
                        ->prefixIcon('heroicon-o-calendar')
                        ->closeOnDateSelection(),
                    DatePicker::make('date_to')
                        ->label('Đến ngày (ngày bắt đầu)')
                        ->displayFormat('d/m/Y')
                        ->native(false)
                        ->prefixIcon('heroicon-o-calendar')
                        ->closeOnDateSelection(),
                ]),
                Grid::make(2)->schema([
                    Select::make('order_type')
                        ->label('Loại đơn / Luồng hàng')
                        ->options([
                            'all' => 'Tất cả',
                            'HHHK' => 'HHHK',
                            'external' => 'Hàng ngoài',
                        ]),
                    Select::make('status')
                        ->label('Trạng thái chuyến')
                        ->options([
                            'all' => 'Tất cả trạng thái (gồm hoàn thành & hủy)',
                            'active' => 'Đang chạy (trừ hoàn thành & hủy)',
                            'unsent' => 'Chưa gửi',
                            'pending' => 'Chờ chạy',
                            'running' => 'Đang chạy',
                            'started' => 'Đã bắt đầu',
                            'arrived_pickup' => 'Đến lấy hàng',
                            'delivering' => 'Đang giao',
                            'arrived_delivery' => 'Đến giao hàng',
                            'delivered' => 'Đã giao',
                            'completed' => 'Hoàn thành',
                            'delayed' => 'Trễ giờ',
                            'driver_swap' => 'Đảo lái',
                            'return_trip' => 'Chuyến không hàng',
                            'cancelled' => 'Đã huỷ',
                        ]),
                ]),
                Grid::make(2)->schema([
                    Select::make('vehicle_owner')
                        ->label('Đơn vị xe')
                        ->options([
                            'all' => 'Tất cả loại xe',
                            'company' => 'Xe công ty',
                            'rent' => 'Xe thuê ngoài',
                        ]),
                    Select::make('place')
                        ->label('Khu vực')
                        ->options(fn (): array => ['all' => 'Tất cả khu vực'] + Area::query()
                            ->where('is_active', true)
                            ->orderBy('sort_order', 'asc')
                            ->pluck('code', 'code')
                            ->map(fn (string $code): string => $code === 'PROVINCE' ? 'Điểm khác' : $code)
                            ->toArray()),
                ]),
                Grid::make(2)->schema([
                    Select::make('vehicle_id')
                        ->label('Xe')
                        ->placeholder('Tất cả xe')
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => Vehicle::query()
                            ->where('is_active', true)
                            ->pluck('plate_number', 'id')
                            ->toArray()),
                    Select::make('driver_id')
                        ->label('Tài xế')
                        ->placeholder('Tất cả tài xế')
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => User::query()
                            ->when(
                                Role::where('name', 'driver')->exists(),
                                fn ($q) => $q->role('driver'),
                            )
                            ->where('is_active', true)
                            ->pluck('name', 'id')
                            ->toArray()),
                ]),
                TextInput::make('search')
                    ->label('Từ khóa tìm kiếm')
                    ->placeholder('Biển số xe, tài xế, mã đơn, khách hàng...'),
            ])
            ->action(fn (array $data, ListTrips $livewire, TripExcelExportService $exportService): StreamedResponse => $livewire->exportExcel($exportService, $data));
    }
}
