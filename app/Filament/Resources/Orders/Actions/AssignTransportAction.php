<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Services\Trip\TripAssignmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\RawJs;
use Illuminate\Support\HtmlString;
use Throwable;

class AssignTransportAction extends CreatesOrderTransportCards
{
    public static function make(): Action
    {
        return Action::make('assign_transport')
            ->label('Gán lái, xe')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->button()
            ->size('xs')
            ->hidden(fn (Order $record): bool => ! $record->status->canAssign())
            ->modal()
            ->modalHeading('Gán lái, xe')
            ->modalDescription('Chọn phương tiện cho đơn hàng này. Lái xe sẽ tự động gán theo xe.')
            ->modalWidth(Width::MaxContent)
            ->stickyModalFooter()
            ->schema([
                Placeholder::make('chargeable_weight_warning')
                    ->label('')
                    ->content(new HtmlString('<div class="p-3 bg-amber-50 border border-amber-200 text-amber-800 dark:bg-amber-950 dark:border-amber-800 dark:text-amber-300 rounded-lg text-sm flex items-center gap-2 mb-2">⚠️ <strong>Cảnh báo:</strong> Đơn hàng ngoài này chưa có trọng tải tính cước. Vui lòng nhập trước khi gán chuyến!</div>'))
                    ->visible(fn (Order $record): bool => $record->type === OrderType::External && ($record->chargeable_weight === null || $record->chargeable_weight === '')),

                TextInput::make('chargeable_weight')
                    ->label('Trọng tải tính cước')
                    ->suffix('tấn')
                    ->mask(RawJs::make('$money($input)'))
                    ->stripCharacters(',')
                    ->numeric()
                    ->default(fn (Order $record) => $record->chargeable_weight)
                    ->required(fn (Order $record): bool => $record->type === OrderType::External)
                    ->visible(fn (Order $record): bool => $record->type === OrderType::External),

                Grid::make(2)
                    ->schema([
                        VehiclePicker::make('vehicle_id')
                            ->label('Phương tiện')
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => self::handleVehicleStateUpdated($set, $state))
                            ->cards(fn (Order $record): array => self::resolveVehicleCards(
                                self::normalizeDecimal($record->total_weight ?? 0),
                                null,
                                null,
                            ))
                            ->searchPlaceholder('Tìm biển số, loại xe...')
                            ->required(),

                        DriverPicker::make('driver_id')
                            ->label('Lái xe')
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => self::handleDriverStateUpdated($set, $state))
                            ->cards(fn (): array => self::resolveDriverCards())
                            ->searchPlaceholder('Tìm tên, email...'),
                    ]),
            ])
            ->modalSubmitAction(fn (Action $action): Action => $action->label('Tạo'))
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('createAndSend', arguments: ['send_immediately' => true])
                    ->label('Tạo và Gửi')
                    ->color('primary'),
            ])
            ->action(function (Order $record, array $data, array $arguments): void {
                $sendImmediately = (bool) ($arguments['send_immediately'] ?? false);
                $status = $sendImmediately ? OrderStatus::Sent : OrderStatus::Assigned;
                self::createTripForOrder($record, $data, $status);
            });
    }

    private static function createTripForOrder(Order $record, array $data, OrderStatus $orderStatus): void
    {
        if ($record->type === OrderType::External) {
            $weight = $data['chargeable_weight'] ?? $record->chargeable_weight;
            if (blank($weight)) {
                Notification::make()
                    ->title('Chưa nhập trọng tải tính cước')
                    ->body('Đơn hàng ngoài bắt buộc phải có Trọng tải tính cước trước khi gán xe.')
                    ->danger()
                    ->send();

                return;
            }
        }

        try {
            if ($record->type === OrderType::External && filled($data['chargeable_weight'] ?? null)) {
                $record->chargeable_weight = $data['chargeable_weight'];
                $record->save();
            }

            app(TripAssignmentService::class)->assign(
                collect([$record]),
                (int) $data['vehicle_id'],
                filled($data['driver_id'] ?? null) ? (int) $data['driver_id'] : null,
                $orderStatus === OrderStatus::Sent,
            );

            $label = $orderStatus === OrderStatus::Sent ? 'Tạo và gửi chuyến' : 'Tạo chuyến';

            Notification::make()
                ->title("{$label} thành công")
                ->body('Đã tạo chuyến và gán đơn hàng.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Lỗi')
                ->body('Không thể tạo chuyến: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}
