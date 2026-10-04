<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Models\Trip;
use App\Services\Notification\DriverNotificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelTripAction
{
    public static function make(): Action
    {
        return Action::make('cancel_trip')
            ->label('Huỷ chuyến')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->hidden(fn (Trip $record): bool => $record->status === TripStatus::Completed || $record->status === TripStatus::Cancelled)
            ->modalHeading('Huỷ chuyến')
            ->modalDescription('Chuyến sẽ bị huỷ, tất cả đơn hàng đang chạy sẽ chuyển sang trạng thái Huỷ. KM sẽ được tính theo số km hiện tại.')
            ->modalSubmitActionLabel('Xác nhận huỷ')
            ->schema([
                Textarea::make('cancel_reason')
                    ->label('Lý do huỷ')
                    // ->required()
                    ->rows(2),
            ])
            ->action(function (Trip $record, array $data): void {
                try {
                    DB::transaction(function () use ($record, $data) {
                        // Cập nhật trip
                        $record->status = TripStatus::Cancelled;
                        $record->cancelled_at = now();
                        $record->save();

                        // Đưa xe về trạng thái sẵn sàng
                        if ($record->vehicle) {
                            $record->vehicle->status = VehicleStatus::On;
                            $record->vehicle->save();
                        }

                        // Huỷ tất cả orders chưa đóng
                        $record->orders()
                            ->whereNotIn('status', [
                                OrderStatus::Completed->value,
                                OrderStatus::Cancelled->value,
                            ])
                            ->update([
                                'status' => OrderStatus::Cancelled->value,
                                'cancelled_at' => now(),
                                'cancel_reason' => $data['cancel_reason'] ?? '',
                            ]);

                        // Tạo checkpoint huỷ chuyến
                        $record->checkpoints()->create([
                            'checkpoint_type' => CheckpointType::Cancelled->value,
                            'occurred_at' => now(),
                            'driver_id' => $record->driver_id,
                            'shift_id' => $record->shift_id,
                        ]);
                    });

                    try {
                        app(DriverNotificationService::class)->sendTripCancelled($record, $data['cancel_reason'] ?? null);
                    } catch (Throwable) {
                    }

                    Notification::make()
                        ->title('Huỷ chuyến thành công')
                        ->body("Chuyến #{$record->trip_code} đã được huỷ.")
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể huỷ chuyến: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
