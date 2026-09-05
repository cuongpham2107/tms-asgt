<?php

namespace App\Filament\Resources\OvertimeRegistrations\Tables;

use App\Enums\OvertimeStatus;
use App\Filament\BaseTable;
use App\Models\DriverShift;
use App\Models\OvertimeRegistration;
use App\Services\Notification\DriverNotificationService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OvertimeRegistrationsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(
                fn (Builder $query) => $query
                    ->with(['driver', 'confirmedBy'])
                    ->orderByRaw(
                        "CASE WHEN status = 'pending' THEN 0 WHEN status = 'confirmed' THEN 1 ELSE 2 END ASC",
                    )
                    ->orderBy('overtime_date', 'desc'),
            )
            ->columns([
                TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('shift_type')
                    ->label('Ca tăng cường')
                    ->badge()
                    ->sortable(),
                TextColumn::make('overtime_date')
                    ->label('Ngày tăng cường')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Ghi chú')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('confirmedBy.name')
                    ->label('Người duyệt')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confirmed_at')
                    ->label('Thời gian duyệt')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Ngày đăng ký')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(OvertimeStatus::class),
                SelectFilter::make('driver_id')
                    ->label('Tài xế')
                    ->relationship('driver', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('overtime_date')
                    ->label('Ngày tăng cường')
                    ->form([
                        DatePicker::make('from_date')->label('Từ ngày'),
                        DatePicker::make('to_date')->label('Đến ngày'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from_date'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate(
                                    'overtime_date',
                                    '>=',
                                    $date,
                                ),
                            )
                            ->when(
                                $data['to_date'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate(
                                    'overtime_date',
                                    '<=',
                                    $date,
                                ),
                            );
                    }),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('Xác nhận')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('primary')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading('Xác nhận ca tăng cường')
                    ->modalDescription(
                        fn (
                            OvertimeRegistration $record,
                        ) => "Bạn có chắc chắn muốn xác nhận ca tăng cường cho tài xế {$record->driver?->name} vào ngày {$record->overtime_date?->format(
                            'd/m/Y',
                        )}? Hệ thống sẽ tự động tạo ca làm việc cho tài xế.",
                    )
                    ->visible(
                        fn (OvertimeRegistration $record) => $record->status ===
                            OvertimeStatus::Pending,
                    )
                    ->action(function (OvertimeRegistration $record) {
                        $record->update([
                            'status' => OvertimeStatus::Confirmed,
                            'confirmed_at' => now(),
                            'confirmed_by' => auth()->id(),
                        ]);

                        DriverShift::create([
                            'driver_id' => $record->driver_id,
                            'shift_type' => $record->shift_type,
                            'is_overtime' => true,
                            'start_time' => $record->overtime_date->startOfDay(),
                        ]);

                        try {
                            app(
                                DriverNotificationService::class,
                            )->sendOvertimeConfirmed($record);
                        } catch (\Throwable $e) {
                            // Notification error shouldn't block action
                        }

                        Notification::make()
                            ->title('Đã xác nhận đăng ký tăng cường')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label('Từ chối')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading('Từ chối đăng ký tăng cường')
                    ->modalDescription(
                        fn (
                            OvertimeRegistration $record,
                        ) => "Bạn có chắc chắn muốn từ chối đăng ký tăng cường của tài xế {$record->driver?->name} vào ngày {$record->overtime_date?->format(
                            'd/m/Y',
                        )}?",
                    )
                    ->visible(
                        fn (OvertimeRegistration $record) => $record->status ===
                            OvertimeStatus::Pending,
                    )
                    ->action(function (OvertimeRegistration $record) {
                        $record->update([
                            'status' => OvertimeStatus::Rejected,
                            'confirmed_at' => now(),
                            'confirmed_by' => auth()->id(),
                        ]);

                        try {
                            app(
                                DriverNotificationService::class,
                            )->sendOvertimeRejected($record);
                        } catch (\Throwable $e) {
                            // Notification error shouldn't block action
                        }

                        Notification::make()
                            ->title('Đã từ chối đăng ký tăng cường')
                            ->warning()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
