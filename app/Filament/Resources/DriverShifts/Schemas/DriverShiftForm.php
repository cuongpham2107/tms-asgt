<?php

namespace App\Filament\Resources\DriverShifts\Schemas;

use App\Enums\ShiftType;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\ShiftVehicle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class DriverShiftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin ca')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('driver_id')
                            ->label('Lái xe')
                            ->relationship('driver', 'name')
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->required(),
                        Select::make('shift_type')
                            ->label('Loại ca')
                            ->prefixIcon(Heroicon::OutlinedClock)
                            ->options(ShiftType::class)
                            ->required(),
                        DateTimePicker::make('start_time')
                            ->label('Bắt đầu ca')
                            ->prefixIcon(Heroicon::OutlinedCalendarDays)
                            ->required(),
                        DateTimePicker::make('end_time')
                            ->label('Kết thúc ca')
                            ->prefixIcon(Heroicon::OutlinedCalendarDays),
                    ]),
                Section::make('Thông tin km')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('total_km')
                            ->label('Tổng km')
                            ->prefixIcon(Heroicon::OutlinedAdjustmentsVertical)
                            ->numeric(),
                        TextInput::make('total_km_loaded')
                            ->label('Km có tải')
                            ->numeric(),
                        TextInput::make('total_km_empty')
                            ->label('Km rỗng')
                            ->numeric(),
                    ]),
                Section::make('Các xe đã sử dụng trong ca')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('shiftVehicles')
                            ->relationship('shiftVehicles')
                            ->label('Danh sách công việc')
                            ->table([
                                TableColumn::make('Xe')->width('150px'),
                                TableColumn::make('Đơn hàng')->width('200px'),
                                TableColumn::make('Bắt đầu')->width('200px'),
                                TableColumn::make('Kết thúc')->width('200px'),
                                TableColumn::make('Km đầu')->width('100px'),
                                TableColumn::make('Km cuối')->width('100px'),
                            ])
                            ->schema([
                                Select::make('vehicle_id')
                                    ->label('Xe')
                                    ->relationship('vehicle', 'plate_number')
                                    ->required(),
                                Placeholder::make('orders_run')
                                    ->label('Đơn hàng')
                                    ->content(function (?ShiftVehicle $record) {
                                        if ($record === null) {
                                            return '-';
                                        }

                                        $orders = Order::where('shift_id', $record->shift_id)
                                            ->where('vehicle_id', $record->vehicle_id)
                                            ->pluck('order_code')
                                            ->toArray();

                                        if (empty($orders)) {
                                            return 'Không có đơn';
                                        }

                                        return implode(', ', $orders);
                                    }),
                                DateTimePicker::make('start_time')
                                    ->label('Bắt đầu')
                                    ->displayFormat('d/m/Y H:i')
                                    ->seconds(false)
                                    ->native(false),
                                DateTimePicker::make('end_time')
                                    ->label('Kết thúc')
                                    ->displayFormat('d/m/Y H:i')
                                    ->seconds(false)
                                    ->native(false),
                                TextInput::make('start_km')
                                    ->label('Km đầu')
                                    ->numeric(),
                                TextInput::make('end_km')
                                    ->label('Km cuối')
                                    ->numeric(),
                            ])
                            ->columns(2)
                            ->addActionLabel('Thêm xe'),
                    ]),
                Section::make('Chi tiết km chạy theo từng đơn hàng')
                    ->columnSpanFull()
                    ->schema([
                        Placeholder::make('orders_km_details')
                            ->label('')
                            ->content(function (?DriverShift $record) {
                                if ($record === null) {
                                    return 'Không có dữ liệu ca';
                                }

                                $orders = $record->orders_with_km_details;
                                if ($orders->isEmpty()) {
                                    return 'Không có đơn hàng nào';
                                }

                                $html = '<table class="w-full text-left border-collapse border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">';
                                $html .= '<thead class="bg-gray-50 dark:bg-gray-800">';
                                $html .= '<tr>';
                                $html .= '<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">Mã đơn hàng</th>';
                                $html .= '<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">Phương tiện</th>';
                                $html .= '<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">Km nhận hàng (Pickup)</th>';
                                $html .= '<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">Km giao xong (Completed)</th>';
                                $html .= '<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">Km chạy có tải</th>';
                                $html .= '<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">Giờ nhận hàng</th>';
                                $html .= '<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">Giờ hoàn thành</th>';
                                $html .= '<th class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 font-semibold text-sm text-gray-700 dark:text-gray-300">Trạng thái</th>';
                                $html .= '</tr>';
                                $html .= '</thead>';
                                $html .= '<tbody class="divide-y divide-gray-200 dark:divide-gray-700">';

                                foreach ($orders as $order) {
                                    $html .= '<tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">';
                                    $html .= '<td class="px-4 py-2 text-sm font-medium text-gray-900 dark:text-gray-100">'.e($order['order_code']).'</td>';
                                    $html .= '<td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">'.e($order['vehicle_plate']).'</td>';
                                    $html .= '<td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">'.e($order['start_km']).'</td>';
                                    $html .= '<td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">'.e($order['end_km']).'</td>';
                                    $html .= '<td class="px-4 py-2 text-sm font-semibold text-green-600 dark:text-green-400">'.e($order['loaded_km']).'</td>';
                                    $html .= '<td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">'.e($order['pickup_time']).'</td>';
                                    $html .= '<td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">'.e($order['completed_time']).'</td>';
                                    $html .= '<td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">'.e($order['status']).'</td>';
                                    $html .= '</tr>';
                                }

                                $html .= '</tbody>';
                                $html .= '</table>';

                                return new HtmlString($html);
                            }),
                    ]),
            ]);
    }
}
