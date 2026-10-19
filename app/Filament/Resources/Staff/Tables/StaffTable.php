<?php

namespace App\Filament\Resources\Staff\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_code')
                    ->label('Mã NV')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->badge()
                    ->color('info')
                    ->placeholder('Chưa có'),
                TextColumn::make('name')
                    ->label('Họ và tên')
                    ->weight('bold')
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
                    })
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('roles.name')
                    ->label('Vai trò / Quyền')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'driver' => 'gray',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'super_admin' => 'Super Admin',
                        'driver' => 'Tài xế',
                        default => ucfirst($state),
                    }),
                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Vai trò')
                    ->relationship('roles', 'name')
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label('Trạng thái hoạt động')
                    ->boolean()
                    ->trueLabel('Đang hoạt động')
                    ->falseLabel('Đã khóa / Ngừng'),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->modalHeading('Chỉnh sửa thông tin nhân viên')
                    ->modalWidth(Width::FourExtraLarge),
                DeleteAction::make()
                    ->iconButton(),
            ], position: RecordActionsPosition::BeforeColumns)
            ->paginated([20, 50, 100])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
