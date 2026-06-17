<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
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
                    ->searchable(),
                // TextColumn::make('email')
                //     ->label('Email')
                //     ->searchable(),
                // TextColumn::make('email_verified_at')
                //     ->label('Xác thực email lúc')
                //     ->dateTime()
                //     ->sortable(),
                TextColumn::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->date()
                    ->sortable(),
                TextColumn::make('license_class')
                    ->label('Hạng bằng lái')
                    ->searchable(),
                // TextColumn::make('license_number')
                //     ->label('Số bằng lái')
                //     ->searchable(),
                // TextColumn::make('license_expiry_date')
                //     ->label('Ngày hết hạn bằng lái')
                //     ->date()
                //     ->sortable(),
                // ImageColumn::make('license_image')
                //     ->label('Ảnh bằng lái'),
                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable(),
                // TextColumn::make('address')
                //     ->label('Địa chỉ')
                //     ->searchable(),
                // TextColumn::make('avatar')
                //     ->label('Ảnh đại diện')
                //     ->searchable(),
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
                // TextColumn::make('cccd_issue_date')
                //     ->label('Ngày cấp CCCD')
                //     ->date()
                //     ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->paginated([20])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
