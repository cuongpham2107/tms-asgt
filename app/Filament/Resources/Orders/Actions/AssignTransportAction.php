<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Notification\DriverNotificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\DB;
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
                $isRent = Vehicle::query()->find($data['vehicle_id'])?->type === VehicleOwnerType::Rent;
                $sendImmediately = $arguments['send_immediately'] ?? false;
                $status = ($isRent || $sendImmediately) ? OrderStatus::Sent : OrderStatus::Assigned;
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
            DB::transaction(function () use ($record, $data, $orderStatus) {
                $trip = Trip::create([
                    'trip_code' => Trip::generateTripCode(),
                    'vehicle_id' => $data['vehicle_id'],
                    'driver_id' => $data['driver_id'] ?? null,
                    'status' => TripStatus::Pending,
                    'start_location_id' => $record->pickup_location_id,
                    'end_location_id' => $record->deliveryPoints()
                        ->orderBy('sequence', 'desc')
                        ->first()?->location_id,
                ]);

                $orderUpdates = [
                    'trip_id' => $trip->id,
                    'status' => $orderStatus,
                ];

                if ($orderStatus === OrderStatus::Sent) {
                    $orderUpdates['sent_at'] = now();
                }

                if ($record->type === OrderType::External && isset($data['chargeable_weight']) && filled($data['chargeable_weight'])) {
                    $orderUpdates['chargeable_weight'] = $data['chargeable_weight'];
                }

                $updated = $record->update($orderUpdates);

                if (! $updated) {
                    throw new \RuntimeException('Không thể gán đơn hàng vào chuyến.');
                }

                static::createCheckpointsForExternalVehicle($trip, collect([$record]));

                if ($orderStatus === OrderStatus::Sent) {
                    try {
                        app(DriverNotificationService::class)->sendOrderAssigned($record, $trip);
                    } catch (Throwable) {
                        // Không ngắt luồng nếu push notification gặp lỗi
                    }
                }

                if (filled($data['vehicle_id'] ?? null)) {
                    $vehicle = Vehicle::query()->find($data['vehicle_id']);

                    if ($vehicle !== null) {
                        $vehicle->status = VehicleStatus::Running;
                        $vehicle->save();
                    }
                }
            });

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
