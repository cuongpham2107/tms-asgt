<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Services\Notification\DriverNotificationService;
use App\Services\Trip\TripCancellationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            ->authorize(fn (Trip $record): bool => (auth()->user()?->can('cancel', $record) ?? false) && (auth()->user()?->can('update', $record) ?? false))
            ->hidden(fn (Trip $record): bool => $record->status === TripStatus::Completed || $record->status === TripStatus::Cancelled)
            ->modalHeading('Huỷ chuyến')
            ->modalDescription('Chuyến sẽ bị huỷ, tất cả đơn hàng đang chạy sẽ chuyển sang trạng thái Huỷ. KM sẽ được tính theo số km hiện tại.')
            ->modalSubmitActionLabel('Xác nhận huỷ')
            ->schema([
                TextInput::make('km_reading')
                    ->label('Số km hiện tại')
                    ->numeric()
                    ->required()
                    ->default(fn (Trip $record): ?float => $record->vehicle?->current_mileage)
                    ->helperText('Km đồng hồ tại thời điểm huỷ chuyến. Mặc định là km hiện tại của xe.'),
                Textarea::make('cancel_reason')
                    ->label('Lý do huỷ')
                    // ->required()
                    ->rows(2),
            ])
            ->action(function (Trip $record, array $data): void {
                try {
                    $kmReading = (float) $data['km_reading'];

                    if ($kmReading <= 0) {
                        Notification::make()
                            ->title('Số km không hợp lệ')
                            ->body('Vui lòng nhập số km lớn hơn 0.')
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($record->start_km !== null && $kmReading < (float) $record->start_km) {
                        Notification::make()
                            ->title('Số km không hợp lệ')
                            ->body('Km huỷ chuyến ('.number_format($kmReading, 1).') phải lớn hơn hoặc bằng Km bắt đầu ('.number_format((float) $record->start_km, 1).').')
                            ->danger()
                            ->send();

                        return;
                    }

                    $maxCheckpointKm = $record->checkpoints()->whereNotNull('km_reading')->max('km_reading');
                    if ($maxCheckpointKm !== null && $kmReading < (float) $maxCheckpointKm) {
                        Notification::make()
                            ->title('Số km không hợp lệ')
                            ->body('Km huỷ chuyến phải >= km cao nhất của chuyến ('.number_format((float) $maxCheckpointKm, 1).' km).')
                            ->danger()
                            ->send();

                        return;
                    }

                    app(TripCancellationService::class)->cancelTrip($record, $kmReading, $data['cancel_reason'] ?? '');

                    try {
                        app(DriverNotificationService::class)->sendTripCancelled($record, $data['cancel_reason'] ?? null);
                    } catch (Throwable) {
                    }

                    Notification::make()
                        ->title('Huỷ chuyến thành công')
                        ->body("Chuyến #{$record->trip_code} đã được huỷ. Km huỷ: ".number_format($kmReading, 1))
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
