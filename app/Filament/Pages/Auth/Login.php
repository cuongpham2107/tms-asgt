<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email hoặc Mã nhân viên')
            ->required()
            ->autocomplete()
            ->autofocus();
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
