<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    protected function getRedirectUrl(): string
    {
        $user = auth('web')->user();

        if (! $user) {
            return parent::getRedirectUrl();
        }

        return match ($user->role) {
            'admin'    => '/admin',
            'reseller' => '/reseller-panel',
            default    => '/', // or wherever you want to send non-staff
        };
    }
}