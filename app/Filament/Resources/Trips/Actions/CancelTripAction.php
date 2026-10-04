<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Exceptions\InvalidTransitionException;
use App\Models\Trip;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\TripStateMachine;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

class CancelTripAction
{
    public static function make(): Action
    {
        return Action::make('cancel_trip')
            ->label('Huỷ chuyến')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Trip $record): bool => $record->status->canCancel())
            ->modalHeading('Huỷ chuyến')
            ->modalDescription('Chuyến sẽ bị huỷ, tất cả đơn hàng đang chạy sẽ chuyển sang trạng thái Huỷ.')
            ->modalSubmitActionLabel('Xác nhận huỷ')
            ->schema([
                Textarea::make('cancel_reason')
                    ->label('Lý do huỷ')
                    // ->required()
                    ->rows(2),
            ])
            ->action(function (Trip $record, array $data): void {
                try {
                    app(TripStateMachine::class)->cancelTrip($record, auth()->user(), $data['cancel_reason'] ?? null);

                    try {
                        app(DriverNotificationService::class)->sendTripCancelled($record, $data['cancel_reason'] ?? null);
                    } catch (Throwable) {
                    }

                    Notification::make()
                        ->title('Huỷ chuyến thành công')
                        ->body("Chuyến #{$record->trip_code} đã được huỷ.")
                        ->success()
                        ->send();
                } catch (InvalidTransitionException $e) {
                    Notification::make()
                        ->title('Không thể huỷ chuyến')
                        ->body($e->getMessage())
                        ->warning()
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
