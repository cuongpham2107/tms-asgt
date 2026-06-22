<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin tài khoản')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Họ và tên')
                            ->required(),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required(),
                        TextInput::make('password')
                            ->label('Mật khẩu')
                            ->password()
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->saved(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create'),
                        DateTimePicker::make('email_verified_at')
                            ->label('Xác thực email lúc'),
                        Toggle::make('is_active')
                            ->label('Đang hoạt động')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Thông tin cá nhân')
                    ->columns(2)
                    ->schema([
                        Grid::make()
                            ->schema([
                                DatePicker::make('date_of_birth')
                                    ->label('Ngày sinh')
                                    ->columnSpanFull(),
                                TextInput::make('phone')
                                    ->label('Số điện thoại')
                                    ->tel()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        FileUpload::make('avatar')
                            ->label('Ảnh đại diện')
                            ->image()
                            ->avatar()
                            ->imageEditor()
                            ->circleCropper()
                            ->disk('public')
                            ->directory('avatars'),
                        TextInput::make('address')
                            ->label('Địa chỉ')
                            ->columnSpanFull(),
                    ]),

                Section::make('Giấy tờ & Bằng lái')
                    ->columns(2)
                    ->schema([
                        TextInput::make('cccd')
                            ->label('Số CCCD'),
                        DatePicker::make('cccd_issue_date')
                            ->label('Ngày cấp CCCD'),
                        TextInput::make('license_class')
                            ->label('Hạng bằng lái'),
                        TextInput::make('license_number')
                            ->label('Số bằng lái'),
                        DatePicker::make('license_expiry_date')
                            ->label('Ngày hết hạn'),
                        FileUpload::make('license_image')
                            ->label('Ảnh bằng lái')
                            ->image()
                            ->imageEditor()
                            ->directory('licenses')
                            ->openable()
                            ->downloadable()
                            ->disk('public')
                            ->previewable(),
                    ]),

                Grid::make()
                    ->columns(2)
                    ->schema([
                        Textarea::make('certificates')
                            ->label('Chứng chỉ')
                            ->placeholder('Nhập thông tin chứng chỉ (nếu có)')
                            ->columnSpanFull(),
                        Select::make('roles')
                            ->label('Vai trò')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
