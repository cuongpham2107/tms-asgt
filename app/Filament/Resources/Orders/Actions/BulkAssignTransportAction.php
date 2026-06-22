<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\OrderPlans\Pages\ListOrderPlans;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Throwable;

class BulkAssignTransportAction extends CreatesOrderTransportCards
{
    public static function make(): BulkAction
    {
        return BulkAction::make('bulk_assign_transport')
            ->label('Gán nhiều đơn hàng cho xe')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->modal()
            ->modalHeading('Gán nhiều đơn hàng cho xe')
            ->modalDescription('Chọn phương tiện cho các đơn hàng được chọn. Lái xe sẽ tự động gán theo xe.')
            ->modalWidth(Width::MaxContent)
            ->stickyModalFooter()
            ->visible(function (BulkAction $action): bool {
                $livewire = $action->getLivewire();

                if ($livewire instanceof ListOrderPlans) {
                    return true;
                }

                if ($livewire instanceof ListOrders) {
                    return $livewire->activeStatusFilter === 'planned';
                }

                return true;
            })
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
                            ->cards(fn (): array => self::resolveVehicleCards(null, null, null))
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
            ->action(function (EloquentCollection $records, array $data): void {
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

                $draftOrders = $records->filter(fn (Order $order): bool => $order->status === OrderStatus::Draft);

                if ($draftOrders->isEmpty()) {
                    Notification::make()
                        ->title('Không có đơn hàng nào hợp lệ')
                        ->body('Chỉ các đơn hàng ở trạng thái Nháp mới có thể gán phương tiện.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $snapshotPlateNumber = null;
                    $snapshotType = null;
                    if (filled($data['vehicle_id'] ?? null)) {
                        $vehicle = Vehicle::query()->find($data['vehicle_id']);
                        if ($vehicle !== null) {
                            $snapshotPlateNumber = $vehicle->plate_number;
                            $snapshotType = $vehicle->vehicle_type?->value;
                        }
                    }

                    Order::query()->whereIn('id', $draftOrders->pluck('id'))->update([
                        'vehicle_id' => $data['vehicle_id'] ?? null,
                        'vehicle_plate_number' => $snapshotPlateNumber,
                        'vehicle_type' => $snapshotType,
                        'driver_id' => $data['driver_id'] ?? null,
                        'status' => (filled($data['driver_id'] ?? null) || filled($data['vehicle_id'] ?? null))
                            ? OrderStatus::Assigned->value
                            : OrderStatus::Draft->value,
                    ]);

                    if (filled($data['vehicle_id'] ?? null)) {
                        $vehicle = Vehicle::query()->find($data['vehicle_id']);

                        if ($vehicle !== null) {
                            $vehicle->status = VehicleStatus::Running;

                            $vehicle->save();
                        }
                    }

                    Notification::make()
                        ->title('Gán phương tiện hàng loạt thành công')
                        ->body('Đã gán phương tiện và lái xe cho '.$draftOrders->count().' đơn hàng.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể gán phương tiện hàng loạt: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            })
            ->deselectRecordsAfterCompletion();
    }
}
