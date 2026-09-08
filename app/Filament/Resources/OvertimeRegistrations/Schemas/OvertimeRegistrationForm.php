<?php

namespace App\Filament\Resources\OvertimeRegistrations\Schemas;

use App\Enums\OvertimeStatus;
use App\Enums\ShiftType;
use App\Models\OvertimeRegistration;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class OvertimeRegistrationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin đăng ký tăng cường')
                    ->description('Chi tiết thông tin đăng ký ca làm việc tăng cường của tài xế')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('driver_id')
                            ->label('Tài xế')
                            ->relationship('driver', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->prefixIcon(Heroicon::OutlinedUser),
                        Select::make('shift_type')
                            ->label('Ca tăng cường')
                            ->options(ShiftType::class)
                            ->required()
                            ->prefixIcon(Heroicon::OutlinedClock),
                        DatePicker::make('overtime_date')
                            ->label('Ngày tăng cường')
                            ->displayFormat('d/m/Y')
                            ->required()
                            ->prefixIcon(Heroicon::OutlinedCalendarDays),
                        Select::make('status')
                            ->label('Trạng thái')
                            ->options(OvertimeStatus::class)
                            ->required()
                            ->prefixIcon(Heroicon::OutlinedCheckCircle)
                            ->default(OvertimeStatus::Pending->value),
                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Thông tin phê duyệt & Hệ thống')
                    ->description('Thông tin xác nhận và thời gian ghi nhận từ hệ thống')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('confirmed_by')
                            ->label('Người duyệt')
                            ->relationship('confirmedBy', 'name')
                            ->disabled()
                            ->prefixIcon(Heroicon::OutlinedUserCircle),
                        DateTimePicker::make('confirmed_at')
                            ->label('Thời gian duyệt')
                            ->displayFormat('d/m/Y H:i')
                            ->disabled()
                            ->prefixIcon(Heroicon::OutlinedClock),
                        Placeholder::make('created_at')
                            ->label('Thời gian đăng ký')
                            ->content(fn (?OvertimeRegistration $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
                        Placeholder::make('updated_at')
                            ->label('Cập nhật lần cuối')
                            ->content(fn (?OvertimeRegistration $record): string => $record?->updated_at?->format('d/m/Y H:i') ?? '—'),
                    ]),
            ]);
    }
}
