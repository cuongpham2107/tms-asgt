<?php

namespace App\Filament\Resources\DriverShifts\Pages;

use App\Filament\Resources\DriverShifts\DriverShiftResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDriverShifts extends ListRecords
{
    protected static string $resource = DriverShiftResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
