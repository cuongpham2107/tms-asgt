<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\OnDutyLocation;
use App\Models\User;
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
                            ->revealable()
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
                        Select::make('station')
                            ->label('Điểm trực')
                            ->options(OnDutyLocation::class),
                    ]),

                Section::make('Căn cước công dân & Định danh')
                    ->columns(2)
                    ->schema([
                        TextInput::make('cccd')
                            ->label('Số CCCD'),
                        DatePicker::make('cccd_issue_date')
                            ->label('Ngày cấp CCCD'),
                    ]),

                Section::make('Giấy phép lái xe (GPLX)')
                    ->icon('heroicon-o-identification')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('license_class')
                            ->label('Hạng bằng lái')
                            ->placeholder('B2, C, FC, E...'),
                        TextInput::make('license_number')
                            ->label('Số bằng lái'),
                        DatePicker::make('license_issue_date')
                            ->label('Ngày cấp GPLX'),
                        DatePicker::make('license_expiry_date')
                            ->label('Ngày hết hạn GPLX')
                            ->helperText(fn (?User $record): ?string => $record?->getLicenseExpiryStatus()['label']),
                        FileUpload::make('license_image')
                            ->label('Ảnh bằng lái')
                            ->image()
                            ->imageEditor()
                            ->directory('licenses')
                            ->openable()
                            ->downloadable()
                            ->disk('public')
                            ->previewable()
                            ->columnSpanFull(),
                    ]),

                Section::make('Chứng chỉ An ninh Hàng không (ANHK)')
                    ->icon('heroicon-o-shield-check')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('aviation_security_cert_number')
                            ->label('Số chứng chỉ ANHK'),
                        DatePicker::make('aviation_security_cert_issue_date')
                            ->label('Ngày cấp ANHK'),
                        DatePicker::make('aviation_security_cert_expiry_date')
                            ->label('Ngày hết hạn ANHK')
                            ->helperText(fn (?User $record): ?string => $record?->getAviationSecurityCertStatus()['label']),
                        FileUpload::make('aviation_security_cert_image')
                            ->label('Ảnh chứng chỉ ANHK')
                            ->image()
                            ->imageEditor()
                            ->directory('certificates/aviation_security')
                            ->openable()
                            ->downloadable()
                            ->disk('public')
                            ->previewable()
                            ->columnSpanFull(),
                    ]),

                Section::make('Chứng chỉ Vận chuyển Hàng nguy hiểm (DG)')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('dangerous_goods_cert_number')
                            ->label('Số chứng chỉ Hàng nguy hiểm'),
                        DatePicker::make('dangerous_goods_cert_issue_date')
                            ->label('Ngày cấp chứng chỉ'),
                        DatePicker::make('dangerous_goods_cert_expiry_date')
                            ->label('Ngày hết hạn chứng chỉ')
                            ->helperText(fn (?User $record): ?string => $record?->getDangerousGoodsCertStatus()['label']),
                        FileUpload::make('dangerous_goods_cert_image')
                            ->label('Ảnh chứng chỉ Hàng nguy hiểm')
                            ->image()
                            ->imageEditor()
                            ->directory('certificates/dangerous_goods')
                            ->openable()
                            ->downloadable()
                            ->disk('public')
                            ->previewable()
                            ->columnSpanFull(),
                    ]),

                Grid::make()
                    ->columns(2)
                    ->schema([
                        Textarea::make('certificates')
                            ->label('Ghi chú chứng chỉ khác')
                            ->placeholder('Nhập thông tin chứng chỉ bổ sung khác (nếu có)')
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
