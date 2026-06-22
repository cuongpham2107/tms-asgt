<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

class SendOrderAction
{
    public static function make(): Action
    {
        return Action::make('send_order')
            ->label('Gửi lệnh')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->hidden(fn (Order $record): bool => ! $record->status->canSend())
            ->requiresConfirmation()
            ->modalHeading('Xác nhận gửi lệnh')
            ->modalDescription('Bạn chắc chắn muốn gửi lệnh cho đơn hàng này không?')
            ->modalSubmitActionLabel('Gửi')
            ->modalCancelActionLabel('Hủy')
            ->action(function (Order $record): void {
                try {
                    if (! $record->status->canSend()) {
                        Notification::make()
                            ->title('Không thể gửi lệnh')
                            ->body('Chỉ có thể gửi lệnh cho đơn hàng đã được gán xe.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Order::query()->whereKey($record->id)->update([
                        'status' => OrderStatus::Sent->value,
                        'sent_at' => now(),
                    ]);

                    Notification::make()
                        ->title('Gửi lệnh thành công')
                        ->body('Đơn hàng đã được gửi.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể gửi lệnh: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
