<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class AdminLogin extends BaseLogin
{
    protected static string $view = 'filament.auth.login'; // optional, remove if you want default

    protected function getRedirectUrl(): string
    {
        return url('/admin');
    }
}