<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\BaseTable;
use App\Filament\Resources\Orders\Actions\AssignTransportAction;
use App\Filament\Resources\Orders\Actions\CancelOrderAction;
use App\Filament\Resources\Orders\Actions\CopyTransportInfoAction;
use App\Filament\Resources\Orders\Actions\CreateReturnTripAction;
use App\Filament\Resources\Orders\Actions\DriverSwapAction;
use App\Filament\Resources\Orders\Actions\SendOrderAction;
use App\Filament\Resources\Orders\Actions\UnsendOrderAction;
use App\Models\Order;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class OrdersTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'deliveryPoints.location',
                'orderCategory',
                'orderType',
                'pickupLocation',
            ]))
            ->columns([
                TextColumn::make('order_code')
                    ->label('Đơn hàng')
                    ->searchable()
                    ->weight('bold')
                    ->formatStateUsing(function (Order $record): HtmlString {
                        $orderCode = e($record->order_code);
                        $orderTypeName = e($record->orderType?->code ? $record->orderType?->code == 'external' ? 'Hàng ngoài' : $record->orderType?->code : 'Chưa xác định');
                        $priorityColor = $record->priority?->getColor() ?? 'gray';
                        $priorityBadgeClasses = self::getStatusBadgeClasses($priorityColor);
                        $priorityLabel = e($record->priority?->getLabel() ?? 'Chưa xác định');

                        return new HtmlString(<<<HTML
                            <div class="inline-flex flex-col gap-1">
                                <span class="font-bold leading-5 text-[#008fd5] dark:text-blue-100">{$orderCode}</span>
                                <div class="inline-flex items-center gap-2">
                                    <span class="rounded-full border border-primary-100 bg-primary-100 px-1.5 py-0.5 text-xs font-semibold text-primary-800 dark:border-primary-800/50 dark:bg-primary-950/40 dark:text-primary-100">{$orderTypeName}</span>
                                    <span class="rounded-full {$priorityBadgeClasses} px-1.5 py-0.5 text-xs font-semibold">{$priorityLabel}</span>
                                </div>
                            </div>
                        HTML);
                    })
                    ->html(),

                TextColumn::make('customer.name')
                    ->label('Khách hàng')
                    ->weight('bold')
                    ->description(fn (Order $record): string => $record->cargo_name ?? '')
                    ->searchable(),
                TextColumn::make('route_timeline')
                    ->state(fn (Order $record): string => $record->order_code)
                    ->formatStateUsing(fn (Order $record): HtmlString => self::renderRouteTimeline($record))
                    ->label('Hành trình')
                    ->html()
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
                TextColumn::make('planned_loading_at')
                    ->label('Thời gian đóng hàng')
                    ->dateTime('H:i d/m/Y')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('total_packages')
                    ->label('Tải trọng')
                    ->formatStateUsing(function (Order $record): HtmlString {
                        $totalPackages = number_format((float) ($record->total_packages ?? 0), 0, ',', '.');
                        $totalWeight = number_format((float) ($record->total_weight ?? 0), 0, ',', '.');

                        return new HtmlString(<<<HTML
                                <div class="flex flex-col gap-1 leading-tight">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">Số kiện: {$totalPackages}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">Tải trọng: {$totalWeight} tấn</div>
                                </div>
                            HTML);
                    })
                    ->sortable(),

                TextColumn::make('vehicle.plate_number')
                    ->label('Phương tiện / Lái xe')
                    ->formatStateUsing(fn (Order $record): HtmlString => self::renderTransportColumn($record))
                    ->html()
                    ->searchable(),
                TextColumn::make('notes')
                    ->label('Ghi chú')
                    ->limit(50),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn ($state): string => (is_object($state) && method_exists($state, 'getColor')) ? ($state->getColor() ?? 'gray') : 'gray')
                    ->icon(fn ($state): ?string => (is_object($state) && method_exists($state, 'getIcon')) ? $state->getIcon() : null)
                    ->sortable(),
                TextColumn::make('createdBy.name')
                    ->label('Người tạo')
                    ->searchable(),

                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('H:i d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->stackedOnMobile()
            ->searchable(false)
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make()
                        ->modalDescription(fn (Order $record): string => 'Loại đơn hàng: '.($record->orderType?->name ?? 'Chưa xác định')),
                    AssignTransportAction::make(),
                    SendOrderAction::make(),
                    UnsendOrderAction::make(),
                    DriverSwapAction::make(),
                    CreateReturnTripAction::make(),
                    CancelOrderAction::make(),
                    DeleteAction::make()
                        ->hidden(fn (Order $record): bool => ! $record->status->canDelete())
                        ->requiresConfirmation()
                        ->modalHeading('Xác nhận xóa đơn')
                        ->modalDescription('Bạn chắc chắn muốn xóa đơn hàng này? Chỉ đơn ở trạng thái Nháp hoặc Đã hủy mới có thể xóa.')
                        ->modalSubmitActionLabel('Xóa')
                        ->modalCancelActionLabel('Hủy'),
                    CopyTransportInfoAction::make(),
                ]),
            ], position: RecordActionsPosition::BeforeColumns);
    }

    private static function renderRouteTimeline(Order $record): HtmlString
    {
        $pickupLocationName = e($record->pickupLocation?->name ?? $record->pickup_address ?? 'Chưa có điểm lấy');
        $deliveryPoints = $record->deliveryPoints->sortBy('sequence');
        $locations = collect([$pickupLocationName]);

        foreach ($deliveryPoints as $deliveryPoint) {
            $locations->push(
                e($deliveryPoint->address ?? $deliveryPoint->location?->name ?? 'Chưa có điểm đến')
            );
        }

        if ($deliveryPoints->isEmpty()) {
            $locations->push(e($record->orderCategory?->code ?? 'Chưa có điểm đến'));
        }

        $arrowIcon = <<<'SVG'
                        <svg  xmlns="http://www.w3.org/2000/svg" width="20" height="20"  
                            fill="currentColor" viewBox="0 0 24 24" >
                            <path d="M6 13h8.09l-3.3 3.29 1.42 1.42 5.7-5.71-5.7-5.71-1.42 1.42 3.3 3.29H6z"></path>
                        </svg>
                    SVG;

        $timeline = $locations
            ->map(function (string $location, int $index) use ($locations, $arrowIcon): string {
                $isLast = $index === $locations->count() - 1;

                return '
                                    <div class="flex items-center gap-1">
                                        <div class="
                                            text-sm
                                            whitespace-nowrap
                                        ">
                                            '.$location.'
                                        </div>
                                        '.(! $isLast ? $arrowIcon : '').'
                                    </div>
                                ';
            })
            ->implode('');

        return new HtmlString(
            '
                            <div class="
                                flex items-center flex-wrap gap-2
                                p-2 
                            ">
                                '.$timeline.'
                            </div>
                            '
        );
    }

    private static function renderTransportColumn(Order $record): HtmlString
    {
        $vehiclePlate = e($record->vehicle?->plate_number ?? 'Chưa phân phương tiện');
        $driverName = e($record->driver?->name ?? 'Chưa phân lái xe');

        return new HtmlString(<<<HTML
            <div class="flex flex-col items-center gap-1.5 leading-tight text-center">
                <div class="inline-flex items-center gap-2 px-1.5 py-0.5 text-xs font-semibold text-gray-700 dark:text-gray-200">
                    <span class="font-bold text-sm text-black">{$vehiclePlate}</span>
                </div>
                <div class="inline-flex items-center gap-2 px-1.5 py-0.5 text-xs font-medium text-gray-500 dark:text-gray-300">
                    <span class="whitespace-nowrap">{$driverName}</span>
                </div>
            </div>
        HTML);
    }

    private static function getStatusBadgeClasses(string $color): string
    {
        return match ($color) {
            'blue' => 'bg-blue-100 text-blue-800 border border-blue-100 dark:bg-blue-950/40 dark:text-blue-100 dark:border-blue-800/50',
            'cyan' => 'bg-cyan-100 text-cyan-800 border border-cyan-100 dark:bg-cyan-950/40 dark:text-cyan-100 dark:border-cyan-800/50',
            'danger' => 'bg-red-100 text-red-800 border border-red-100 dark:bg-red-950/40 dark:text-red-100 dark:border-red-800/50',
            'warning' => 'bg-amber-100 text-amber-800 border border-amber-100 dark:bg-amber-950/40 dark:text-amber-100 dark:border-amber-800/50',
            'amber' => 'bg-amber-100 text-amber-800 border border-amber-100 dark:bg-amber-950/40 dark:text-amber-100 dark:border-amber-800/50',
            'orange' => 'bg-orange-100 text-orange-800 border border-orange-100 dark:bg-orange-950/40 dark:text-orange-100 dark:border-orange-800/50',
            'lime' => 'bg-lime-100 text-lime-800 border border-lime-100 dark:bg-lime-950/40 dark:text-lime-100 dark:border-lime-800/50',
            'success' => 'bg-emerald-100 text-emerald-800 border border-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-100 dark:border-emerald-800/50',
            'info' => 'bg-sky-100 text-sky-800 border border-sky-100 dark:bg-sky-950/40 dark:text-sky-100 dark:border-sky-800/50',
            'purple' => 'bg-purple-100 text-purple-800 border border-purple-100 dark:bg-purple-950/40 dark:text-purple-100 dark:border-purple-800/50',
            'slate' => 'bg-slate-100 text-slate-800 border border-slate-100 dark:bg-slate-950/40 dark:text-slate-100 dark:border-slate-800/50',
            'primary' => 'bg-primary-100 text-primary-800 border border-primary-100 dark:bg-primary-900/40 dark:text-primary-100 dark:border-primary-800/50',
            default => 'bg-gray-100 text-gray-800 border border-gray-100 dark:bg-gray-900/40 dark:text-gray-100 dark:border-gray-700',
        };
    }
}
