<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Throwable;

class AssignTransportAction extends CreatesOrderTransportCards
{
    public static function make(): Action
    {
        return Action::make('assign_transport')
            ->label('Gán xe')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->hidden(fn (Order $record): bool => ! $record->status->canAssign())
            ->modal()
            ->modalHeading('Gán phương tiện')
            ->modalDescription('Chọn phương tiện cho đơn hàng này. Lái xe sẽ tự động gán theo xe.')
            ->modalWidth('5xl')
            ->stickyModalFooter()
            ->schema([
                VehiclePicker::make('vehicle_id')
                    ->label('Phương tiện')
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        if ($state) {
                            $vehicle = Vehicle::find($state);
                            $set('driver_id', $vehicle?->current_driver_id ?? null);
                        } else {
                            $set('driver_id', null);
                        }
                    })
                    ->cards(fn (Get $get): array => self::resolveVehicleCards(
                        self::normalizeDecimal($get('total_weight') ?? 0),
                        null,
                    ))
                    ->searchPlaceholder('Tìm biển số, loại xe...')
                    ->required(),
                Hidden::make('driver_id'),

                Checkbox::make('override_shift_check')
                    ->label('Bỏ qua kiểm tra ca')
                    ->helperText('Cho phép gán xe dù xe không có ca đang hoạt động')
                    ->live()
                    ->visible(fn (Get $get): bool => filled($get('vehicle_id'))),
            ])
            ->action(function (Order $record, array $data): void {
                $vehicle = Vehicle::find($data['vehicle_id'] ?? null);

                if ($vehicle !== null && ! ($data['override_shift_check'] ?? false)) {
                    $hasActiveShift = $vehicle->driverShifts()->whereNull('end_time')->exists();

                    if (! $hasActiveShift) {
                        Notification::make()
                            ->warning()
                            ->title('Xe chưa có ca làm việc')
                            ->body('Xe này hiện không có ca nào đang hoạt động. Đánh dấu "Bỏ qua kiểm tra ca" nếu vẫn muốn gán xe.')
                            ->send();

                        return;
                    }
                }

                try {
                    Order::query()->whereKey($record->id)->update([
                        'vehicle_id' => $data['vehicle_id'] ?? null,
                        'driver_id' => $data['driver_id'] ?? null,
                        'status' => (filled($data['driver_id'] ?? null) || filled($data['vehicle_id'] ?? null))
                            ? OrderStatus::Assigned->value
                            : $record->status->value,
                    ]);

                    if (filled($data['vehicle_id'] ?? null)) {
                        $vehicle = Vehicle::query()->find($data['vehicle_id']);

                        if ($vehicle !== null) {
                            $vehicle->status = VehicleStatus::Running;

                            if (filled($data['driver_id'] ?? null)) {
                                $vehicle->current_driver_id = (int) $data['driver_id'];
                            }

                            $vehicle->save();
                        }
                    }

                    Notification::make()
                        ->title('Gán phương tiện thành công')
                        ->body('Đơn hàng đã được gán phương tiện và lái xe.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể gán phương tiện: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
