<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\Order;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\TripStateMachine;
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
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::Sent
                && ! in_array($record->trip?->status, [TripStatus::Delivering, TripStatus::ArrivedDelivery, TripStatus::Delivered, TripStatus::Completed], true))
            ->requiresConfirmation()
            ->modalHeading('Xác nhận thu hồi lệnh')
            ->modalDescription('Bạn chắc chắn muốn thu hồi lệnh cho đơn hàng này không?')
            ->modalSubmitActionLabel('Thu hồi')
            ->modalCancelActionLabel('Hủy')
            ->action(function (Order $record): void {
                try {
                    app(TripStateMachine::class)->recallOrder($record);

                    try {
                        app(DriverNotificationService::class)->sendOrderRecalled($record);
                    } catch (Throwable) {
                    }

                    Notification::make()
                        ->title('Thu hồi thành công')
                        ->body('Đơn hàng đã được thu hồi, quay lại trạng thái gán xe.')
                        ->success()
                        ->send();
                } catch (InvalidTransitionException $e) {
                    Notification::make()
                        ->title('Không thể thu hồi')
                        ->body($e->getMessage())
                        ->warning()
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
