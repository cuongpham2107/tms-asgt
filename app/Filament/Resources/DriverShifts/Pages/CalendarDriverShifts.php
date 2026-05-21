<?php

namespace App\Filament\Resources\DriverShifts\Pages;

use App\Filament\Resources\DriverShifts\DriverShiftResource;
use App\Filament\Resources\DriverShifts\Widgets\DriverShiftCalendarWidget;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;

class CalendarDriverShifts extends Page
{
    protected static string $resource = DriverShiftResource::class;

    protected string $view = 'filament.resources.driver-shifts.pages.calendar-page';

    protected static ?string $title = 'Lịch ca trực lái xe';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tableView')
                ->label('Xem dạng bảng')
                ->color('gray')
                ->icon('heroicon-o-table-cells')
                ->url(DriverShiftResource::getUrl('table')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DriverShiftCalendarWidget::class,
        ];
    }
}
