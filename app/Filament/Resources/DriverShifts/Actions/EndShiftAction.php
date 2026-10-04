<?php

namespace App\Filament\Resources\DriverShifts\Actions;

use App\Models\DriverShift;
use App\Services\DriverShiftService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class EndShiftAction
{
    public static function make(): Action
    {
        return Action::make('end_shift')
            ->label('Kết thúc ca')
            ->icon('heroicon-o-stop')
            ->color('danger')
            ->visible(fn (DriverShift $record): bool => $record->end_time === null)
            ->requiresConfirmation()
            ->modalHeading('Kết thúc ca trực')
            ->modalDescription('Xác nhận kết thúc ca làm việc này? Chuyến đã giao xong sẽ được hoàn thành, chuyến đang chạy sẽ chuyển sang Đảo lái để gán tài xế khác.')
            ->modalSubmitActionLabel('Kết thúc ca')
            ->action(function (array $data, DriverShift $record): void {
                app(DriverShiftService::class)->endShift($record);

                Notification::make()
                    ->success()
                    ->title('Đã kết thúc ca')
                    ->body('Các chuyến dở đã chuyển sang Đảo lái (nếu có).')
                    ->send();
            });
    }
}
