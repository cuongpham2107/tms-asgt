<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Throwable;

class BulkSendOrderAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('bulk_send_order')
            ->label('Gửi lệnh hàng loạt')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Xác nhận gửi lệnh hàng loạt')
            ->modalDescription('Bạn chắc chắn muốn gửi lệnh cho các đơn hàng đã chọn?')
            ->modalSubmitActionLabel('Gửi')
            ->modalCancelActionLabel('Hủy')
            ->visible(fn (Collection $records): bool => $records->isNotEmpty()
                && $records->every(fn (Order $record): bool => $record->status === OrderStatus::Assigned))
            ->action(function (Collection $records): void {
                $assignOrders = $records->filter(fn (Order $order): bool => $order->status->canSend());

                if ($assignOrders->isEmpty()) {
                    Notification::make()
                        ->title('Không có đơn hàng nào hợp lệ')
                        ->body('Chỉ các đơn hàng ở trạng thái Đã gán xe mới có thể gửi lệnh.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    Order::query()->whereIn('id', $assignOrders->pluck('id'))->update([
                        'status' => OrderStatus::Sent->value,
                        'sent_at' => now(),
                    ]);

                    Notification::make()
                        ->title('Gửi lệnh hàng loạt thành công')
                        ->body('Đã gửi lệnh cho '.$assignOrders->count().' đơn hàng.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể gửi lệnh hàng loạt: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            })
            ->deselectRecordsAfterCompletion();
    }
}
