<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\OrderStatus;
use App\Models\Trip;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\TripStateMachine;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            ->visible(fn (Trip $record): bool => $record->orders->contains(fn ($o) => $o->status === OrderStatus::Assigned))
            ->requiresConfirmation()
            ->modalHeading('Xác nhận gửi lệnh chuyến đi')
            ->modalDescription('Bạn chắc chắn muốn chuyển tất cả đơn hàng trong chuyến này sang trạng thái Đã gửi?')
            ->modalSubmitActionLabel('Gửi lệnh')
            ->modalCancelActionLabel('Hủy')
            ->action(function (Trip $record): void {
                try {
                    $stateMachine = app(TripStateMachine::class);
                    $assignedOrders = $record->orders()->where('status', OrderStatus::Assigned->value)->get();
                    DB::transaction(fn () => $assignedOrders->each(fn ($order) => $stateMachine->sendOrder($order)));
                    $count = $assignedOrders->count();

                    if ($count === 0) {
                        Notification::make()
                            ->title('Không có đơn hàng nào cần gửi')
                            ->body('Không tìm thấy đơn hàng nào ở trạng thái chờ gửi.')
                            ->warning()
                            ->send();

                        return;
                    }

                    // Gửi push notification qua Firebase cho lái xe
                    try {
                        app(DriverNotificationService::class)->sendTripDispatched($record, $count);
                    } catch (Throwable $e) {
                        Log::warning('Lỗi gửi push notification khi gửi lệnh chuyến: '.$e->getMessage(), ['exception' => $e]);
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
