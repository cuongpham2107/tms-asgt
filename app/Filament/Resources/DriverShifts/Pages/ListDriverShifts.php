<?php

namespace App\Filament\Resources\DriverShifts\Pages;

use App\Enums\ShiftType;
use App\Filament\Resources\DriverShifts\DriverShiftResource;
use App\Models\DriverShift;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;

class ListDriverShifts extends ListRecords
{
    protected static string $resource = DriverShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkCreateShifts')
                ->label('Tạo ca hàng loạt')
                ->icon('heroicon-o-squares-plus')
                ->color('success')
                ->modalWidth('lg')
                ->modalHeading('Tạo ca trực hàng loạt')
                ->form([
                    Select::make('driver_ids')
                        ->label('Lái xe')
                        ->multiple()
                        ->options(fn () => User::whereHas('roles', fn ($q) => $q->where('name', 'driver'))->pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->preload(),
                    Select::make('shift_type')
                        ->label('Loại ca')
                        ->options(ShiftType::class)
                        ->required(),
                    DateTimePicker::make('start_time')
                        ->label('Bắt đầu ca')
                        ->required()
                        ->default(now()),
                    DateTimePicker::make('end_time')
                        ->label('Kết thúc ca'),
                ])
                ->action(function (array $data): void {
                    $driverIds = $data['driver_ids'];
                    $shiftType = $data['shift_type'];
                    $startTime = $data['start_time'];
                    $endTime = $data['end_time'] ?? null;

                    DB::transaction(function () use ($driverIds, $shiftType, $startTime, $endTime) {
                        foreach ($driverIds as $driverId) {
                            DriverShift::create([
                                'driver_id' => $driverId,
                                'shift_type' => $shiftType,
                                'start_time' => $startTime,
                                'end_time' => $endTime,
                            ]);
                        }
                    });

                    Notification::make()
                        ->title('Thành công')
                        ->body('Đã tạo thành công ca trực cho ' . count($driverIds) . ' lái xe.')
                        ->success()
                        ->send();
                }),
            Action::make('calendarView')
                ->label('Xem dạng lịch')
                ->color('gray')
                ->icon('heroicon-o-calendar-days')
                ->url(DriverShiftResource::getUrl('index')),
            CreateAction::make()
                ->label('Tạo ca lái mới')
                ->icon('heroicon-o-plus')
                ->slideOver(),
        ];
    }
}
