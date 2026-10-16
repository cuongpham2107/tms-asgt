<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Notification\DriverNotificationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

class UnsendOrderAction
{
    public static function make(): Action
    {
        return Action::make('unsend_order')
            ->label('Thu hồi lệnh')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->hidden(fn (Order $record): bool => ! $record->status->canRecall())
            ->requiresConfirmation()
            ->modalHeading('Xác nhận thu hồi lệnh')
            ->modalDescription('Bạn chắc chắn muốn thu hồi lệnh cho đơn hàng này không? Đơn sẽ quay lại trạng thái Nháp.')
            ->modalSubmitActionLabel('Thu hồi')
            ->modalCancelActionLabel('Hủy')
            ->action(function (Order $record): void {
                try {
                    if (! $record->status->canRecall()) {
                        Notification::make()
                            ->title('Không thể thu hồi')
                            ->body('Chỉ có thể thu hồi đơn hàng trước khi lái xe bắt đầu đi giao hàng.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $trip = $record->trip;

                    Order::query()->whereKey($record->id)->update([
                        'status' => OrderStatus::Draft->value,
                        'trip_id' => null,
                        'sent_at' => null,
                    ]);

                    if ($trip && $trip->orders()->count() === 0) {
                        $trip->delete();
                    }

                    try {
                        app(DriverNotificationService::class)->sendOrderRecalled($record);
                    } catch (Throwable) {
                    }

                    Notification::make()
                        ->title('Thu hồi thành công')
                        ->body('Đơn hàng đã được thu hồi về trạng thái Nháp.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể thu hồi: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
