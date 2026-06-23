<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Họ và tên')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->date()
                    ->sortable(),
                TextColumn::make('license_class')
                    ->label('Hạng bằng lái')
                    ->alignCenter()
                    ->badge()
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Đang hoạt động')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Cập nhật lúc')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cccd')
                    ->label('Số CCCD')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->modalWidth(Width::SevenExtraLarge),
            ], position: RecordActionsPosition::BeforeColumns)
            ->paginated([20])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
