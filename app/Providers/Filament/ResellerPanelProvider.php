<?php

namespace App\Providers\Filament;

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
            ->path('reseller-panel')
            ->login()
            ->authGuard('web')
            ->colors([
                'primary' => Color::Purple,
            ])

->discoverResources(
    in: app_path('Filament/Reseller/Resources'),
    for: 'App\\Filament\\Reseller\\Resources'
)

            // IMPORTANT: do NOT discover all resources for resellers (security + clean nav)
            // We'll manually register reseller-safe resources later.
            // For now, keep it empty so the panel loads.
            ->pages([
                \Filament\Pages\Dashboard::class,
            ])

            ->middleware([
		\App\Http\Middleware\EnsureUserIsReseller::class,
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

            // Reseller-only access gate
            ->authMiddleware([
                \Filament\Http\Middleware\Authenticate::class,
            ]);
    }
}
