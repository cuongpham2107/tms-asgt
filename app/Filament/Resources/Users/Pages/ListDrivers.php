<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;

class ListDrivers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm lái xe')
                ->icon('heroicon-o-user-plus')
                ->modalHeading('Thêm lái xe')
                ->modalSubheading('Tạo tài khoản lái xe mới.')
                ->modalButton('Tạo tài khoản')
                ->modalWidth(Width::SevenExtraLarge),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', 'driver')));
    }
}
