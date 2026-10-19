<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListStaff extends ListRecords
{
    protected static string $resource = StaffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm nhân viên')
                ->icon('heroicon-o-user-plus')
                ->modalHeading('Thêm tài khoản nhân viên')
                ->modalSubheading('Tạo tài khoản quản trị hoặc nhân viên văn phòng mới.')
                ->modalButton('Tạo tài khoản')
                ->modalWidth(Width::FourExtraLarge),
        ];
    }
}
