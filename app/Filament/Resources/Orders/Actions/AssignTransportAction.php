<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
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
            ->modalWidth(Width::MaxContent)
            ->stickyModalFooter()
            ->schema([
                Grid::make(2)
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
                            ->cards(fn (Order $record): array => self::resolveVehicleCards(
                                self::normalizeDecimal($record->total_weight ?? 0),
                                null,
                                $record->vehicle_id,
                            ))
                            ->searchPlaceholder('Tìm biển số, loại xe...')
                            ->required(),

                        DriverPicker::make('driver_id')
                            ->label('Lái xe')
                            ->live()
                            ->cards(fn (): array => self::resolveDriverCards())
                            ->searchPlaceholder('Tìm tên, email...')
                            ->required(),
                    ]),

                Checkbox::make('override_shift_check')
                    ->label('Bỏ qua kiểm tra ca')
                    ->helperText('Cho phép gán xe dù xe không có ca đang hoạt động')
                    ->default(true)
                    ->live()
                    ->visible(fn (Get $get): bool => filled($get('vehicle_id'))),
            ])
            ->action(function (Order $record, array $data): void {
                $vehicle = Vehicle::find($data['vehicle_id'] ?? null);

                if ($vehicle !== null && ! ($data['override_shift_check'] ?? false)) {
                    $driverId = $data['driver_id'] ?? null;
                    $driver = $driverId ? User::find($driverId) : null;

                    $hasActiveShift = $vehicle->driverShifts()->whereNull('driver_shifts.end_time')->exists()
                        || ($driver !== null && $driver->driverShifts()->whereNull('driver_shifts.end_time')->exists());

                    if (! $hasActiveShift) {
                        Notification::make()
                            ->warning()
                            ->title('Xe hoặc tài xế chưa có ca làm việc')
                            ->body('Phương tiện hoặc tài xế được chọn hiện không có ca nào đang hoạt động. Đánh dấu "Bỏ qua kiểm tra ca" nếu vẫn muốn gán.')
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

                            // Vehicle.current_driver_id is a static/default field — not modified here.

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
