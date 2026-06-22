<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Trip;
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

                    $record->refresh();
                    if ($record->vehicle_id !== null && $record->trip_id === null) {
                        $trip = Trip::firstOrCreate(
                            ['vehicle_id' => $record->vehicle_id, 'status' => 'pending'],
                            ['status' => 'pending'],
                        );

                        Order::whereKey($record->id)
                            ->whereNull('trip_id')
                            ->update(['trip_id' => $trip->id]);
                    }

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
