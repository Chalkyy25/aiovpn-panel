<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class ResellerLogin extends BaseLogin
{
    // Remove the $view override

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