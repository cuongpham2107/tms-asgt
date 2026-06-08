<?php

namespace App\Filament\Resources\DriverShifts\Actions;

use App\Models\DriverShift;
use App\Services\ShiftKmCalculatorService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

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
            ->modalDescription('Xác nhận kết thúc ca làm việc này? Hệ thống sẽ tự động tính toán km dựa trên dữ liệu đã ghi nhận.')
            ->modalSubmitActionLabel('Kết thúc ca')
            ->action(function (DriverShift $record): void {
                DB::beginTransaction();
                try {
                    $record->end_time = now();
                    $record->save();

                    $segment = $record->currentShiftVehicle();
                    if ($segment) {
                        $segment->end_time = $record->end_time;
                        $segment->save();
                    }

                    app(ShiftKmCalculatorService::class)->calculate($record);

                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }

                Notification::make()
                    ->success()
                    ->title('Đã kết thúc ca')
                    ->body(sprintf(
                        'Tổng km: %s | Có tải: %s | Rỗng: %s',
                        number_format($record->total_km, 1),
                        number_format($record->total_km_loaded, 1),
                        number_format($record->total_km_empty, 1),
                    ))
                    ->send();
            });
    }
}
