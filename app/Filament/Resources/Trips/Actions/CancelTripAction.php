<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Models\Trip;
use App\Services\ShiftKmCalculatorService;
use App\Services\TripKmCalculatorService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelTripAction
{
    public static function make(): Action
    {
        return Action::make('cancel_trip')
            ->label('Huỷ chuyến')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
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

                    DB::transaction(function () use ($record, $kmReading, $data) {
                        // Tính partial KM
                        app(TripKmCalculatorService::class)->calculate($record, endKm: $kmReading);
                        $record->refresh();

                        // Cập nhật trip
                        $record->end_km = $kmReading;
                        $record->status = TripStatus::Cancelled;
                        $record->cancelled_at = now();
                        $record->save();

                        // Cập nhật km xe và đưa xe về trạng thái sẵn sàng
                        if ($record->vehicle) {
                            $record->vehicle->current_mileage = $kmReading;
                            $record->vehicle->status = VehicleStatus::On;
                            $record->vehicle->save();
                        }

                        // Huỷ tất cả orders chưa đóng
                        $record->orders()
                            ->whereNotIn('status', [
                                OrderStatus::Completed->value,
                                OrderStatus::Cancelled->value,
                            ])
                            ->update([
                                'status' => OrderStatus::Cancelled->value,
                                'cancelled_at' => now(),
                                'cancel_reason' => $data['cancel_reason'] ?? '',
                            ]);

                        // Tạo checkpoint huỷ chuyến
                        $record->checkpoints()->create([
                            'checkpoint_type' => CheckpointType::Cancelled->value,
                            'km_reading' => $kmReading,
                            'occurred_at' => now(),
                            'driver_id' => $record->driver_id,
                            'shift_id' => $record->shift_id,
                        ]);

                        // Tính lại km ca
                        if ($record->shift_id) {
                            app(ShiftKmCalculatorService::class)->calculate($record->shift);
                        }
                    });

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
