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
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class BulkAssignTransportAction extends CreatesOrderTransportCards
{
    public static function make(): BulkAction
    {
        return BulkAction::make('bulk_assign_transport')
            ->label('Tạo chuyến')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->modal()
            ->modalHeading('Tạo chuyến cho nhiều đơn hàng')
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
                            ->searchPlaceholder('Tìm tên, email...'),
                    ]),
                Toggle::make('send_immediately')
                    ->label('Gửi chuyến ngay cho tài xế')
                    ->helperText('Bật để chuyển trạng thái đơn hàng thành Đã gửi')
                    ->default(false),

            ])
            ->modalSubmitActionLabel('Tạo chuyến')
            ->action(function (Collection $records, array $data): void {
                $status = ! empty($data['send_immediately']) ? OrderStatus::Sent : OrderStatus::Assigned;
                self::createTripForOrders($records, $data, $status);
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function createTripForOrders(Collection $records, array $data, OrderStatus $orderStatus): void
    {
        $draftOrders = $records->filter(fn (Order $order): bool => $order->status === OrderStatus::Draft);

        if ($draftOrders->isEmpty()) {
            Notification::make()
                ->title('Không có đơn hàng nào hợp lệ')
                ->body('Chỉ các đơn hàng ở trạng thái Nháp mới có thể tạo chuyến.')
                ->warning()
                ->send();

            return;
        }

        try {
            $label = $orderStatus === OrderStatus::Sent ? 'Tạo và gửi chuyến' : 'Tạo chuyến';

            DB::transaction(function () use ($draftOrders, $data, $orderStatus) {
                $sorted = $draftOrders->sortBy('planned_loading_at')->values();
                $firstOrder = $sorted->first();
                $lastOrder = $sorted->last();

                $trip = Trip::create([
                    'trip_code' => Trip::generateTripCode(),
                    'vehicle_id' => $data['vehicle_id'],
                    'driver_id' => $data['driver_id'] ?? null,
                    'status' => TripStatus::Pending,
                    'start_location_id' => $firstOrder?->pickup_location_id,
                    'end_location_id' => $lastOrder?->deliveryPoints()?->orderBy('sequence', 'desc')->first()?->location_id,
                ]);

                $sequence = 0;
                foreach ($draftOrders as $order) {
                    $updated = $order->update([
                        'trip_id' => $trip->id,
                        'trip_sequence' => $sequence++,
                        'status' => $orderStatus,
                    ]);

                    if (! $updated) {
                        throw new \RuntimeException("Không thể gán đơn hàng {$order->order_code} vào chuyến.");
                    }
                }

                static::createCheckpointsForExternalVehicle($trip, $draftOrders);

                if (filled($data['vehicle_id'] ?? null)) {
                    $vehicle = Vehicle::query()->find($data['vehicle_id']);

                    if ($vehicle !== null) {
                        $vehicle->status = VehicleStatus::Running;
                        $vehicle->save();
                    }
                }
            });

            Notification::make()
                ->title("{$label} thành công")
                ->body('Đã tạo chuyến và gán '.$draftOrders->count().' đơn hàng.')
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
