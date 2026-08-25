<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
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
            ->modalHeading('Tạo chuyến cho nhiều đơn hàng')
            ->modalDescription('Chọn phương tiện cho các đơn hàng được chọn. Lái xe sẽ tự động gán theo xe.')
            ->modalWidth(Width::MaxContent)
            ->stickyModalFooter()
            ->schema([
                Placeholder::make('missing_weight_warning')
                    ->label('')
                    ->content(function (Collection $records) {
                        $missing = $records->filter(
                            fn (Order $o): bool => $o->type === OrderType::External && ($o->chargeable_weight === null || $o->chargeable_weight === '')
                        );
                        if ($missing->isEmpty()) {
                            return null;
                        }
                        $codes = $missing->pluck('order_code')->implode(', ');

                        return new HtmlString("<div class='p-3 bg-amber-50 border border-amber-200 text-amber-800 dark:bg-amber-950 dark:border-amber-800 dark:text-amber-300 rounded-lg text-sm mb-2'>⚠️ <strong>Cảnh báo:</strong> Đơn hàng ngoài (<strong>{$codes}</strong>) chưa có trọng tải tính cước! Vui lòng cập nhật trọng tải trước khi gán chuyến.</div>");
                    }),

                Grid::make(2)
                    ->schema([
                        VehiclePicker::make('vehicle_id')
                            ->label('Phương tiện')
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => self::handleVehicleStateUpdated($set, $state))
                            ->cards(fn (): array => self::resolveVehicleCards(null, null, null))
                            ->searchPlaceholder('Tìm biển số, loại xe...')
                            ->required(),

                        DriverPicker::make('driver_id')
                            ->label('Lái xe')
                            ->live()
                            ->afterStateUpdated(fn (Set $set, $state) => self::handleDriverStateUpdated($set, $state))
                            ->cards(fn (): array => self::resolveDriverCards())
                            ->searchPlaceholder('Tìm tên, email...'),
                    ]),
                Toggle::make('send_immediately')
                    ->label('Gửi chuyến ngay cho tài xế')
                    ->helperText('Bật để chuyển trạng thái đơn hàng thành Đã gửi')
                    ->default(false),

            ])
            ->modalSubmitActionLabel('Tạo')
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

        $missingWeightOrders = $draftOrders->filter(
            fn (Order $order): bool => $order->type === OrderType::External && ($order->chargeable_weight === null || $order->chargeable_weight === '')
        );

        if ($missingWeightOrders->isNotEmpty()) {
            $codes = $missingWeightOrders->pluck('order_code')->implode(', ');
            Notification::make()
                ->title('Chưa nhập trọng tải tính cước')
                ->body("Đơn hàng ngoài ({$codes}) chưa có Trọng tải tính cước. Vui lòng cập nhật trước khi gán xe.")
                ->danger()
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
                    'end_location_id' => $lastOrder?->deliveryPoints()?->orderBy('sequence', 'desc')?->first()?->location_id,
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
