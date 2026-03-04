<?php

namespace App\Providers\Filament;

use App\Filament\Auth\ResellerLogin;
use App\Http\Middleware\SetSessionCookieForHost;
use App\Http\Middleware\EnsureUserIsReseller;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Hasnayeen\Themes\ThemesPlugin;
use Hasnayeen\Themes\Http\Middleware\SetTheme;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use App\Http\Middleware\VerifyCsrfToken;
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

            // panel-specific login
            ->login(ResellerLogin::class)

            ->authGuard('web')
            ->colors([
                'primary' => Color::Purple,
            ])

            ->plugin(
                ThemesPlugin::make()
                    ->canViewThemesPage(fn () => in_array(auth('web')->user()?->role, ['admin', 'reseller'], true))
            )

            ->discoverResources(in: app_path('Filament/Reseller/Resources'), for: 'App\\Filament\\Reseller\\Resources')
            ->discoverPages(in: app_path('Filament/Reseller/Pages'), for: 'App\\Filament\\Reseller\\Pages')
            ->discoverWidgets(in: app_path('Filament/Reseller/Widgets'), for: 'App\\Filament\\Reseller\\Widgets')

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                SetSessionCookieForHost::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,

                SetTheme::class,
            ])

            ->authMiddleware([
                Authenticate::class,
                EnsureUserIsReseller::class,
            ]);
    }
}