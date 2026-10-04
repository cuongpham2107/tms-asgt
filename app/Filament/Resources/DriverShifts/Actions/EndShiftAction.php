<?php

namespace App\Filament\Resources\DriverShifts\Actions;

use App\Enums\CheckpointType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
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
            ->action(function (array $data, DriverShift $record): void {
                DB::beginTransaction();
                try {
                    $record->end_time = now();
                    $record->save();

                    // Auto driver_swap: chuyển trip đang active có đơn hàng chưa hoàn thành sang driver_swap
                    $incompleteTrips = Trip::where('driver_id', $record->driver_id)
                        ->where(function ($query) {
                            $query->whereHas('orders', function ($q) {
                                $q->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value]);
                            })->orWhere(function ($q) {
                                $q->where('is_empty_run', true)
                                    ->whereIn('status', [
                                        TripStatus::Started,
                                        TripStatus::ArrivedPickup,
                                        TripStatus::Delivering,
                                        TripStatus::ArrivedDelivery,
                                        TripStatus::Delivered,
                                        TripStatus::ReturnTrip,
                                    ]);
                            });
                        })
                        ->whereIn('status', [
                            TripStatus::Started,
                            TripStatus::ArrivedPickup,
                            TripStatus::Delivering,
                            TripStatus::ArrivedDelivery,
                            TripStatus::Delivered,
                            TripStatus::ReturnTrip,
                        ])
                        ->get();

                    foreach ($incompleteTrips as $trip) {
                        $trip->status = TripStatus::DriverSwap;
                        $trip->shift_id = null;
                        $trip->save();

                        $trip->orders()
                            ->whereIn('status', [OrderStatus::Sent->value, OrderStatus::InTransit->value])
                            ->update(['status' => OrderStatus::DriverSwap->value]);

                        TripCheckpoint::create([
                            'trip_id' => $trip->id,
                            'driver_id' => $record->driver_id,
                            'shift_id' => $record->id,
                            'checkpoint_type' => CheckpointType::DriverSwap->value,
                            'occurred_at' => now(),
                        ]);
                    }

                    // Clean up trips that were driver_swapped via EndHandler
                    $driverSwappedTrips = Trip::where('driver_id', $record->driver_id)
                        ->where('shift_id', $record->id)
                        ->where('status', TripStatus::DriverSwap)
                        ->get();

                    foreach ($driverSwappedTrips as $trip) {
                        $trip->shift_id = null;
                        $trip->save();
                    }

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
                        number_format((float) ($record->total_km ?? 0), 1),
                        number_format((float) ($record->total_km_loaded ?? 0), 1),
                        number_format((float) ($record->total_km_empty ?? 0), 1),
                    ))
                    ->send();
            });
    }
}
