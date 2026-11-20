<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Services\Notification\DriverNotificationService;
use App\Services\Order\OrderDispatchService;
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
            ->authorize(fn (Order $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->hidden(fn (Order $record): bool => ! static::canRecall($record))
            ->requiresConfirmation()
            ->modalHeading('Xác nhận thu hồi lệnh')
            ->modalDescription('Bạn chắc chắn muốn thu hồi lệnh cho đơn hàng này không? Đơn sẽ quay lại trạng thái Nháp.')
            ->modalSubmitActionLabel('Thu hồi')
            ->modalCancelActionLabel('Hủy')
            ->action(function (Order $record): void {
                try {
                    if (! static::canRecall($record)) {
                        Notification::make()
                            ->title('Không thể thu hồi')
                            ->body('Chỉ thu hồi được đơn Đã gửi khi chuyến còn Chờ chạy (lái xe chưa bắt đầu).')
                            ->warning()
                            ->send();

                        return;
                    }

                    // Nạp sẵn chuyến / tài xế trước khi chuyến bị xoá để thông báo đúng tài xế; service cập nhật trên bản đọc mới.
                    $record->loadMissing('trip.driver');
                    app(OrderDispatchService::class)->unsend($record);

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

    /**
     * Chỉ thu hồi khi đơn Đã gửi và chuyến của đơn còn Chờ chạy (lái xe chưa bắt đầu).
     */
    public static function canRecall(Order $record): bool
    {
        return $record->status->canRecall()
            && ($record->trip === null || $record->trip->isPending());
    }
}
