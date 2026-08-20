<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\BaseResource;
use App\Filament\Plugins\ActivitylogPlugin;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends BaseResource
{
    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activity-logs';

    public static function getPlugin(): ?ActivitylogPlugin
    {
        try {
            return filament('activitylog');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()?->getNavigationGroup() ?? 'Quản lý danh mục';
    }

    public static function getNavigationIcon(): ?string
    {
        return static::getPlugin()?->getNavigationIcon() ?? 'heroicon-o-book-open';
    }

    public static function getNavigationSort(): ?int
    {
        return static::getPlugin()?->getNavigationSort() ?? 6;
    }

    public static function getModelLabel(): string
    {
        return static::getPlugin()?->getLabel() ?? 'Nhật ký hoạt động';
    }

    public static function getPluralModelLabel(): string
    {
        return static::getPlugin()?->getPluralLabel() ?? 'Nhật ký hoạt động';
    }

    public static function getNavigationBadge(): ?string
    {
        if (static::getPlugin()?->getNavigationCountBadge()) {
            return (string) static::getModel()::count();
        }

        return null;
    }

    public static function canViewAny(): bool
    {
        $plugin = static::getPlugin();
        if ($plugin !== null) {
            return $plugin->isAuthorized();
        }

        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->can('view_any_activitylog') || $user->can('ViewAny:Activity');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('causer')->latest())
            ->columns([
                TextColumn::make('event')
                    ->label('Sự kiện')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => 'Tạo mới',
                        'updated' => 'Cập nhật',
                        'deleted' => 'Xóa',
                        'restored' => 'Khôi phục',
                        default => $state ? ucfirst($state) : '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'restored' => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (?string $state): ?string => match ($state) {
                        'created' => 'heroicon-m-check-badge',
                        'updated' => 'heroicon-m-pencil-square',
                        'deleted' => 'heroicon-m-trash',
                        'restored' => 'heroicon-m-arrow-path',
                        default => null,
                    })
                    ->sortable(),

                TextColumn::make('subject_type')
                    ->label('Đối tượng')
                    ->formatStateUsing(function (Activity $record): string {
                        if (! $record->subject_type) {
                            return '—';
                        }

                        $className = class_basename($record->subject_type);
                        $translateCallback = static::getPlugin()?->getTranslateSubjectCallback();

                        if ($translateCallback) {
                            $translated = $translateCallback($className);
                            if ($translated && $translated !== 'models.'.$className) {
                                return $translated.' #'.$record->subject_id;
                            }
                        }

                        $modelNames = [
                            'Order' => 'Đơn hàng',
                            'Trip' => 'Chuyến xe',
                            'Vehicle' => 'Phương tiện',
                            'User' => 'Tài khoản',
                            'Customer' => 'Khách hàng',
                            'Location' => 'Địa điểm',
                            'DriverShift' => 'Ca làm việc',
                        ];

                        $label = $modelNames[$className] ?? $className;

                        return $label.' #'.$record->subject_id;
                    })
                    ->searchable(),

                TextColumn::make('description')
                    ->label('Mô tả')
                    ->limit(40)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('causer.name')
                    ->label('Người thực hiện')
                    ->default('Hệ thống')
                    ->icon('heroicon-m-user')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->dateTime('H:i:s d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('view_details')
                    ->label('Chi tiết')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->slideOver()
                    ->modalHeading(fn (Activity $record): string => 'Chi tiết hoạt động #'.$record->id)
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(function (Activity $record) {
                        return view('filament.actions.activity-log-single-detail', [
                            'activity' => $record,
                        ]);
                    }),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Sự kiện')
                    ->options([
                        'created' => 'Tạo mới',
                        'updated' => 'Cập nhật',
                        'deleted' => 'Xóa',
                    ]),

                SelectFilter::make('subject_type')
                    ->label('Loại đối tượng')
                    ->options([
                        'App\Models\Order' => 'Đơn hàng',
                        'App\Models\Trip' => 'Chuyến xe',
                        'App\Models\Vehicle' => 'Phương tiện',
                        'App\Models\User' => 'Tài khoản',
                    ]),

                Filter::make('created_at')
                    ->label('Thời gian')
                    ->form([
                        DatePicker::make('created_from')->label('Từ ngày'),
                        DatePicker::make('created_until')->label('Đến ngày'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
