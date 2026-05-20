<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

class CancelOrderAction
{
    public static function make(): Action
    {
        return Action::make('cancel_order')
            ->label('Huỷ chuyến')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->hidden(fn (Order $record): bool => ! $record->status->canCancel())
            ->requiresConfirmation()
            ->modalHeading('Xác nhận huỷ chuyến')
            ->modalDescription('Bạn chắc chắn muốn huỷ chuyến hàng này không?')
            ->modalSubmitActionLabel('Huỷ chuyến')
            ->modalCancelActionLabel('Hủy')
            ->form([
                Textarea::make('cancel_reason')
                    ->label('Lý do huỷ')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (Order $record, array $data): void {
                try {
                    if (! $record->status->canCancel()) {
                        Notification::make()
                            ->title('Không thể huỷ chuyến')
                            ->body('Không thể huỷ chuyến đơn hàng ở trạng thái này.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Order::query()->whereKey($record->id)->update([
                        'status' => OrderStatus::Cancelled->value,
                        'cancelled_at' => now(),
                        'cancel_reason' => $data['cancel_reason'],
                    ]);

                    Notification::make()
                        ->title('Huỷ chuyến thành công')
                        ->body('Đơn hàng đã được huỷ.')
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
