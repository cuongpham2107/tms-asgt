<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Area;
use App\Models\Customer;
use App\Services\Export\TripExcelExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportOrdersExcelAction
{
    public static function make(): Action
    {
        return Action::make('exportExcel')
            ->label('Xuất Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->modalHeading('Xuất Excel danh sách đơn hàng')
            ->modalDescription('Tùy chọn bộ lọc dữ liệu đơn hàng trước khi xuất file Excel.')
            ->modalSubmitActionLabel('Xuất dữ liệu')
            ->modalWidth(Width::TwoExtraLarge)
            ->fillForm(fn (ListOrders $livewire): array => [
                'date_from' => $livewire->startDate,
                'date_to' => $livewire->endDate,
                'order_type' => $livewire->activeOrderTypeFilter ?? 'all',
                'status' => $livewire->activeStatusFilter ?? 'all',
                'place' => $livewire->activePlaceFilter ?? 'all',
                'customer_id' => null,
                'search' => $livewire->orderSearch,
                'show_mine_only' => $livewire->showMineOnly,
            ])
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('date_from')
                        ->label('Từ ngày (ngày bốc hàng)')
                        ->displayFormat('d/m/Y')
                        ->native(false)
                        ->prefixIcon('heroicon-o-calendar')
                        ->closeOnDateSelection(),
                    DatePicker::make('date_to')
                        ->label('Đến ngày (ngày bốc hàng)')
                        ->displayFormat('d/m/Y')
                        ->native(false)
                        ->prefixIcon('heroicon-o-calendar')
                        ->closeOnDateSelection(),
                ]),
                Grid::make(2)->schema([
                    Select::make('order_type')
                        ->label('Loại đơn hàng')
                        ->options([
                            'all' => 'Tất cả loại đơn',
                            'HHHK' => 'Hàng hóa hàng không',
                            'external' => 'Hàng ngoài',
                        ]),
                    Select::make('status')
                        ->label('Trạng thái đơn')
                        ->options([
                            'all' => 'Tất cả (gồm hoàn thành & hủy)',
                            'active' => 'Đang xử lý (trừ hoàn thành & hủy)',
                            'draft' => 'Nháp',
                            'assigned' => 'Đã gán xe',
                            'sent' => 'Đã gửi',
                            'in_transit' => 'Đang vận chuyển',
                            'driver_swap' => 'Đảo lái',
                            'completed' => 'Hoàn thành',
                            'cancelled' => 'Hủy',
                        ]),
                ]),
                Grid::make(2)->schema([
                    Select::make('place')
                        ->label('Khu vực')
                        ->options(fn (): array => ['all' => 'Tất cả khu vực'] + Area::query()
                            ->where('is_active', true)
                            ->orderBy('sort_order', 'asc')
                            ->pluck('code', 'code')
                            ->map(fn (string $code): string => $code === 'PROVINCE' ? 'Điểm khác' : $code)
                            ->toArray()),
                    Select::make('customer_id')
                        ->label('Khách hàng')
                        ->placeholder('Tất cả khách hàng')
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => Customer::query()
                            ->where('is_active', true)
                            ->orderBy('code', 'asc')
                            ->pluck('code', 'id')
                            ->toArray()),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('search')
                        ->label('Từ khóa tìm kiếm')
                        ->placeholder('Mã đơn, hàng hóa, xe, lái xe...'),
                    Toggle::make('show_mine_only')
                        ->label('Chỉ xuất đơn của tôi')
                        ->inline(false),
                ]),
            ])
            ->action(fn (array $data, ListOrders $livewire, TripExcelExportService $exportService): StreamedResponse => $livewire->exportExcel($exportService, $data));
    }
}
