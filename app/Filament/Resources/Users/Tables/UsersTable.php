<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Filters\ListFilterUsers;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Họ và tên')
                    ->weight('bold')
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
                    }),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->date()
                    ->sortable(),
                TextColumn::make('license_class')
                    ->label('Hạng bằng')
                    ->alignCenter()
                    ->badge()
                    ->searchable(),
                TextColumn::make('license_expiry_date')
                    ->label('Hạn GPLX')
                    ->badge()
                    ->color(fn (?User $record): string => $record?->getLicenseExpiryStatus()['color'] ?? 'gray')
                    ->state(fn (?User $record): string => $record?->getLicenseExpiryStatus()['formatted_date'] ?? '—')
                    ->description(fn (?User $record): ?string => $record?->license_expiry_date ? $record->getLicenseExpiryStatus()['label'] : null)
                    ->sortable(),
                TextColumn::make('aviation_security_cert_expiry_date')
                    ->label('Hạn CC ANHK')
                    ->badge()
                    ->color(fn (?User $record): string => $record?->getAviationSecurityCertStatus()['color'] ?? 'gray')
                    ->state(fn (?User $record): string => $record?->getAviationSecurityCertStatus()['formatted_date'] ?? '—')
                    ->description(fn (?User $record): ?string => $record?->aviation_security_cert_expiry_date ? $record->getAviationSecurityCertStatus()['label'] : null)
                    ->sortable(),
                TextColumn::make('dangerous_goods_cert_expiry_date')
                    ->label('Hạn CC Hàng nguy hiểm')
                    ->badge()
                    ->color(fn (?User $record): string => $record?->getDangerousGoodsCertStatus()['color'] ?? 'gray')
                    ->state(fn (?User $record): string => $record?->getDangerousGoodsCertStatus()['formatted_date'] ?? '—')
                    ->description(fn (?User $record): ?string => $record?->dangerous_goods_cert_expiry_date ? $record->getDangerousGoodsCertStatus()['label'] : null)
                    ->sortable(),
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
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('station')
                    ->label('Điểm trực')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                ListFilterUsers::make(),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(1)
            ->deferFilters(false)
            ->deferLoading()
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
