<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsureUserIsReseller;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class ResellerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('reseller')
            ->path('reseller')
            ->login(\App\Filament\Auth\Login::class)
            ->authGuard('web')
            ->colors([
                'primary' => Color::Purple,
            ])
            ->discoverResources(
                in: app_path('Filament/Reseller/Resources'),
                for: 'App\\Filament\\Reseller\\Resources'
            )
            ->discoverPages(
                in: app_path('Filament/Reseller/Pages'),
                for: 'App\\Filament\\Reseller\\Pages'
            )
            ->discoverWidgets(
                in: app_path('Filament/Reseller/Widgets'),
                for: 'App\\Filament\\Reseller\\Widgets'
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                \Filament\Http\Middleware\Authenticate::class,
                EnsureUserIsReseller::class,
            ]);
    }
}