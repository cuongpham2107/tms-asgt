<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\TripCancellationService;
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
            ->authorize(fn (Order $record): bool => (auth()->user()?->can('cancel', $record) ?? false) && (auth()->user()?->can('update', $record) ?? false))
            ->hidden(fn (Order $record): bool => $record->status !== OrderStatus::Draft)
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
                    if (! $record->status->canCancel()) {
                        Notification::make()
                            ->title('Không thể huỷ đơn hàng')
                            ->body('Không thể huỷ đơn hàng ở trạng thái này.')
                            ->warning()
                            ->send();

                        return;
                    }

                    // $record giữ dữ liệu cũ để gửi thông báo; service cập nhật trên bản đọc mới.
                    app(TripCancellationService::class)->cancelOrder($record, $data['cancel_reason'] ?? null);

                    try {
                        app(DriverNotificationService::class)->sendOrderCancelled($record, $data['cancel_reason'] ?? null);
                    } catch (Throwable) {
                    }

                    Notification::make()
                        ->title('Huỷ đơn hàng thành công')
                        ->body('Đơn hàng đã được huỷ.')
                        ->success()
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
