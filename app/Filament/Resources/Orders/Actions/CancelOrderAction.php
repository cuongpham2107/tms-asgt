<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Exceptions\InvalidTransitionException;
use App\Models\Order;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\TripStateMachine;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

class CancelOrderAction
{
    public static function make(): Action
    {
        return Action::make('cancel_order')
            ->label('Huỷ đơn')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Order $record): bool => $record->status->canCancel())
            ->requiresConfirmation()
            ->modalHeading('Xác nhận huỷ chuyến')
            ->modalDescription('Bạn chắc chắn muốn huỷ chuyến hàng này không?')
            ->modalSubmitActionLabel('Huỷ chuyến')
            ->modalCancelActionLabel('Hủy')
            ->form([
                Textarea::make('cancel_reason')
                    ->label('Lý do huỷ')
                    // ->required()
                    ->rows(3),
            ])
            ->action(function (Order $record, array $data): void {
                try {
                    app(TripStateMachine::class)->cancelOrder($record, auth()->user(), $data['cancel_reason'] ?? null);

                    try {
                        app(DriverNotificationService::class)->sendOrderCancelled($record, $data['cancel_reason'] ?? null);
                    } catch (Throwable) {
                    }

                    Notification::make()
                        ->title('Huỷ đơn hàng thành công')
                        ->body('Đơn hàng đã được huỷ.')
                        ->success()
                        ->send();
                } catch (InvalidTransitionException $e) {
                    Notification::make()
                        ->title('Không thể huỷ đơn hàng')
                        ->body($e->getMessage())
                        ->warning()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không thể huỷ đơn hàng: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
