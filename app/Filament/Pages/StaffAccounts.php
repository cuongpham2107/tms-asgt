<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use UnitEnum;

class StaffAccounts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Cấu hình';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Tài khoản nhân viên';

    protected static ?string $title = 'Quản lý tài khoản nhân viên';

    protected static ?string $slug = 'nhan-vien';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.staff-accounts';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return (bool) $user->is_active && ! $user->hasRole('driver');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', 'driver'))
                    ->with('roles')
            )
            ->columns([
                TextColumn::make('index')
                    ->label('STT')
                    ->rowIndex()
                    ->alignCenter(),
                TextColumn::make('employee_code')
                    ->label('Mã NV')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),
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
                TextColumn::make('roles.name')
                    ->label('Vai trò / Quyền')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        default => 'info',
                    })
                    ->separator(', '),
                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label('Hoạt động')
                    ->boolean()
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm tài khoản')
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Thêm tài khoản nhân viên')
                    ->modalWidth(Width::Large)
                    ->model(User::class)
                    ->schema([
                        TextInput::make('employee_code')
                            ->label('Mã nhân viên')
                            ->placeholder('VD: ASGT-00123')
                            ->unique(User::class, 'employee_code')
                            ->maxLength(50),
                        TextInput::make('name')
                            ->label('Họ và tên')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(User::class, 'email')
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Số điện thoại')
                            ->tel()
                            ->maxLength(20),
                        TextInput::make('password')
                            ->label('Mật khẩu')
                            ->password()
                            ->revealable()
                            ->default('66668888')
                            ->required()
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state)),
                        Select::make('roles')
                            ->label('Vai trò / Quyền')
                            ->relationship(
                                name: 'roles',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->where('name', '!=', 'driver')
                            )
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->default(fn () => Role::where('name', 'super_admin')->pluck('id')->all()),
                        Toggle::make('is_active')
                            ->label('Đang hoạt động')
                            ->default(true),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Sửa')
                    ->iconButton()
                    ->modalHeading('Chỉnh sửa tài khoản nhân viên')
                    ->modalWidth(Width::Large)
                    ->schema([
                        TextInput::make('employee_code')
                            ->label('Mã nhân viên')
                            ->placeholder('VD: ASGT-00123')
                            ->unique(User::class, 'employee_code', ignoreRecord: true)
                            ->maxLength(50),
                        TextInput::make('name')
                            ->label('Họ và tên')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(User::class, 'email', ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Số điện thoại')
                            ->tel()
                            ->maxLength(20),
                        TextInput::make('password')
                            ->label('Đổi mật khẩu mới')
                            ->password()
                            ->revealable()
                            ->placeholder('Để trống nếu không đổi')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state)),
                        Select::make('roles')
                            ->label('Vai trò / Quyền')
                            ->relationship(
                                name: 'roles',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->where('name', '!=', 'driver')
                            )
                            ->multiple()
                            ->preload()
                            ->searchable(),
                        Toggle::make('is_active')
                            ->label('Đang hoạt động'),
                    ]),
                DeleteAction::make()
                    ->label('Xóa')
                    ->iconButton()
                    ->modalHeading('Xóa tài khoản nhân viên')
                    ->hidden(fn (User $record): bool => $record->id === auth()->id()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
