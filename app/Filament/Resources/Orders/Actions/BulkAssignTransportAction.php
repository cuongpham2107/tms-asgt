<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
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

            ])
            ->action(function (EloquentCollection $records, array $data): void {
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
                    DB::transaction(function () use ($draftOrders, $data) {
                        $trip = Trip::create([
                            'trip_code' => Trip::generateTripCode(),
                            'vehicle_id' => $data['vehicle_id'],
                            'driver_id' => $data['driver_id'],
                            'status' => TripStatus::Pending,
                        ]);

                        $orderIds = $draftOrders->pluck('id');
                        $sequence = 0;
                        foreach ($draftOrders as $order) {
                            $order->update([
                                'trip_id' => $trip->id,
                                'trip_sequence' => $sequence++,
                                'status' => OrderStatus::Assigned,
                            ]);
                        }

                        if (filled($data['vehicle_id'] ?? null)) {
                            $vehicle = Vehicle::query()->find($data['vehicle_id']);

                            if ($vehicle !== null) {
                                $vehicle->status = VehicleStatus::Running;
                                $vehicle->save();
                            }
                        }
                    });

                    Notification::make()
                        ->title('Gán phương tiện hàng loạt thành công')
                        ->body('Đã tạo chuyến và gán '.$draftOrders->count().' đơn hàng.')
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
