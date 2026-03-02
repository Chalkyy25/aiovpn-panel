<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class ResellerLogin extends BaseLogin
{
    protected static string $view = 'filament.auth.login'; // optional

    protected function getRedirectUrl(): string
    {
        return url('/reseller');
    }
}