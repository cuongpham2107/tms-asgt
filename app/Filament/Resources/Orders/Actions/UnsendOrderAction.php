<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
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
            ->modalDescription('Bạn chắc chắn muốn thu hồi lệnh cho đơn hàng này không?')
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

                    $record->update([
                        'status' => OrderStatus::Assigned,
                        'sent_at' => null,
                    ]);

                    Notification::make()
                        ->title('Thu hồi thành công')
                        ->body('Đơn hàng đã được thu hồi, quay lại trạng thái gán xe.')
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
