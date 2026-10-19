<?php

namespace App\Filament\Resources\Staff\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Thông tin tài khoản')
                    ->columns(2)
                    ->schema([
                        TextInput::make('employee_code')
                            ->label('Mã nhân viên')
                            ->placeholder('VD: ASGT-00695')
                            ->unique(ignoreRecord: true)
                            ->maxLength(50),
                        TextInput::make('name')
                            ->label('Họ và tên')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email đăng nhập')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('password')
                            ->label('Mật khẩu')
                            ->password()
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->placeholder(fn (string $operation): string => $operation === 'edit' ? 'Để trống nếu không đổi' : 'Nhập mật khẩu'),
                        Select::make('roles')
                            ->label('Vai trò / Quyền hạn')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->default(fn () => Role::where('name', 'super_admin')->pluck('id')->all())
                            ->preload()
                            ->searchable()
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->inline(false)
                            ->label('Đang hoạt động')
                            ->default(true)
                            ->required(),
                    ]),

                Section::make('Thông tin cá nhân & liên hệ')
                    ->columns(2)
                    ->schema([
                        TextInput::make('phone')
                            ->label('Số điện thoại')
                            ->tel(),
                        DatePicker::make('date_of_birth')
                            ->label('Ngày sinh'),
                        TextInput::make('cccd')
                            ->label('Số CCCD'),
                        DatePicker::make('cccd_issue_date')
                            ->label('Ngày cấp CCCD'),
                        TextInput::make('address')
                            ->label('Địa chỉ')
                            ->columnSpanFull(),
                        FileUpload::make('avatar')
                            ->label('Ảnh đại diện')
                            ->image()
                            ->avatar()
                            ->imageEditor()
                            ->circleCropper()
                            ->disk('public')
                            ->directory('avatars')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
