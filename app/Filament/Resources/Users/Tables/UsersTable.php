<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
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
                Filter::make('expired_certificates')
                    ->label('Có chứng chỉ đã hết hạn')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q) {
                        $today = now()->toDateString();
                        $q->where(fn ($sub) => $sub->whereNotNull('license_expiry_date')->where('license_expiry_date', '<', $today))
                            ->orWhere(fn ($sub) => $sub->whereNotNull('aviation_security_cert_expiry_date')->where('aviation_security_cert_expiry_date', '<', $today))
                            ->orWhere(fn ($sub) => $sub->whereNotNull('dangerous_goods_cert_expiry_date')->where('dangerous_goods_cert_expiry_date', '<', $today));
                    })),

                Filter::make('expiring_soon_certificates')
                    ->label('Có chứng chỉ sắp hết hạn (30 ngày)')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q) {
                        $today = now()->toDateString();
                        $in30Days = now()->addDays(30)->toDateString();
                        $q->whereBetween('license_expiry_date', [$today, $in30Days])
                            ->orWhereBetween('aviation_security_cert_expiry_date', [$today, $in30Days])
                            ->orWhereBetween('dangerous_goods_cert_expiry_date', [$today, $in30Days]);
                    })),
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
