<?php

namespace App\Providers\Filament;

use App\Filament\Auth\AdminLogin;
use App\Filament\Widgets\ActiveNowUsers;
use App\Filament\Widgets\AdminStats;
use App\Filament\Widgets\ConnectionsByServer;
use App\Filament\Widgets\ConnectionsTrend;
use App\Filament\Widgets\DashboardLinks;
use App\Filament\Widgets\ExpiringSoonUsers;
use App\Filament\Widgets\RecentConnections;
use App\Filament\Widgets\ServerStatus;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SetSessionCookieForHost;
use App\Http\Middleware\VerifyCsrfToken;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')

            ->viteTheme('resources/css/filament/admin/theme.css')

            ->login(AdminLogin::class)
            ->brandLogo(asset('images/AIO-Logo.svg'))
            ->passwordreset()
            ->emailverification()

            ->authGuard('web')

            ->colors([
            'primary' => Color::Violet,
            'success' => Color::Emerald,
            'danger' => Color::Rose,
            'warning' => Color::Amber,
            'info' => Color::Sky,
                    ])

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')

            ->widgets([
                AdminStats::class,
                ActiveNowUsers::class,
                ConnectionsTrend::class,
                DashboardLinks::class,
                ExpiringSoonUsers::class,
                ConnectionsByServer::class,
                ServerStatus::class,
                RecentConnections::class,
            ])

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
            ])

            ->authMiddleware([
                Authenticate::class,
                EnsureUserIsAdmin::class,
            ]);
    }
}