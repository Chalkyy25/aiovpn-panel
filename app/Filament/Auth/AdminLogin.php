<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class AdminLogin extends BaseLogin
{
    // Remove the $view override (Filament v3 uses its own view)

    protected function getRedirectUrl(): string
    {
        $user = auth('web')->user();

        if (! $user) {
            return parent::getRedirectUrl();
        }

        return match ($user->role) {
            'admin'    => '/admin',
            'reseller' => '/reseller',
            default    => '/',
        };
    }
}