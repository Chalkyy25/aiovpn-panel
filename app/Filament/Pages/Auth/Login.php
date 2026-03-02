<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

class Login extends BaseLogin
{
    protected function getRedirectUrl(): string
    {
        $user = Auth::user();

        if (! $user) {
            return '/';
        }

        return $user->role === 'admin'
            ? Filament::getPanel('admin')->getUrl()
            : Filament::getPanel('reseller')->getUrl();
    }
}