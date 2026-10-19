<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

class Login extends BaseLogin
{
    protected static string $layout = 'filament.pages.auth.split-layout';

    protected string $view = 'filament.pages.auth.login';

    public function hasLogo(): bool
    {
        return false;
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email hoặc Mã nhân viên')
            ->placeholder('VD: ASGT-00123 hoặc name@asgl.net.vn')
            ->required()
            ->autocomplete('username')
            ->autofocus();
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->label('Mật khẩu')
            ->placeholder('Nhập mật khẩu của bạn');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim((string) ($data['email'] ?? ''));
        $cleanLogin = str_replace('-', '', strtoupper($login));

        $user = User::query()
            ->where('email', $login)
            ->orWhereRaw('LOWER(email) = ?', [strtolower($login)])
            ->orWhere('employee_code', $login)
            ->orWhereRaw('UPPER(employee_code) = ?', [strtoupper($login)])
            ->orWhereRaw('REPLACE(UPPER(employee_code), "-", "") = ?', [$cleanLogin])
            ->first();

        if ($user) {
            return [
                'email' => $user->email,
                'password' => $data['password'],
            ];
        }

        return [
            'email' => $login,
            'password' => $data['password'],
        ];
    }
}
