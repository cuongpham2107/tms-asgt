<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\OrderStatus;
use App\Models\Trip;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

class SendTripAction
{
    public static function make(): Action
    {
        return Action::make('send_trip')
            ->label('Gửi lệnh')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->button()
            ->size('xs')
            ->visible(fn (Trip $record): bool => $record->orders->contains(fn ($o) => in_array($o->status, [OrderStatus::Assigned, OrderStatus::Draft])))
            ->requiresConfirmation()
            ->modalHeading('Xác nhận gửi lệnh chuyến đi')
            ->modalDescription('Bạn chắc chắn muốn chuyển tất cả đơn hàng trong chuyến này sang trạng thái Đã gửi?')
            ->modalSubmitActionLabel('Gửi lệnh')
            ->modalCancelActionLabel('Hủy')
            ->action(function (Trip $record): void {
                try {
                    $count = $record->orders()
                        ->whereIn('status', [OrderStatus::Assigned->value, OrderStatus::Draft->value])
                        ->update([
                            'status' => OrderStatus::Sent->value,
                            'sent_at' => now(),
                        ]);

                    if ($count === 0) {
                        Notification::make()
                            ->title('Không có đơn hàng nào cần gửi')
                            ->body('Không tìm thấy đơn hàng nào ở trạng thái chờ gửi.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Gửi lệnh thành công')
                        ->body("Đã gửi lệnh cho {$count} đơn hàng trong chuyến #{$record->trip_code}.")
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
