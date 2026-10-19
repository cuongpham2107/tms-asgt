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
        $login = trim($data['email'] ?? '');
        $cleanCode = str_replace(['-', ' ', '.'], '', $login);

        // Tìm người dùng theo employee_code hoặc email (hỗ trợ hoa/thường, có/không dấu gạch)
        $user = User::query()
            ->where('employee_code', $login)
            ->orWhereRaw('LOWER(employee_code) = ?', [strtolower($login)])
            ->orWhereRaw("REPLACE(REPLACE(LOWER(employee_code), '-', ''), ' ', '') = ?", [strtolower($cleanCode)])
            ->orWhere('email', $login)
            ->orWhereRaw('LOWER(email) = ?', [strtolower($login)])
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
