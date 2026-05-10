<?php

namespace App\Filament\Widgets;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStats extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $now = now();

        /*
        |--------------------------------------------------------------------------
        | SERVERS
        |--------------------------------------------------------------------------
        */

        $serversTotal = VpnServer::count();

        $serversOnline = VpnServer::query()
            ->where('is_online', true)
            ->count();

        $serversOffline = max(0, $serversTotal - $serversOnline);

        /*
        |--------------------------------------------------------------------------
        | USERS
        |--------------------------------------------------------------------------
        */

        $vpnUsersTotal = VpnUser::query()->count();

        $vpnUsersActive = VpnUser::query()
            ->where('is_active', true)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | CONNECTIONS
        |--------------------------------------------------------------------------
        */

        $liveConnections = VpnConnection::query()
            ->live($now)
            ->count();

        $usersOnline = VpnConnection::query()
            ->live($now)
            ->distinct('vpn_user_id')
            ->count('vpn_user_id');

        /*
        |--------------------------------------------------------------------------
        | STALE CONNECTIONS
        |--------------------------------------------------------------------------
        */

        $staleConnections = VpnConnection::query()
            ->stale($now)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | TODAY CONNECTIONS
        |--------------------------------------------------------------------------
        */

        $connectionsToday = VpnConnection::query()
            ->whereDate('created_at', today())
            ->count();

        return [

            /*
            |--------------------------------------------------------------------------
            | ONLINE USERS
            |--------------------------------------------------------------------------
            */

            Stat::make('Users Online', number_format($usersOnline))
                ->description('Currently connected users')
                ->descriptionIcon('heroicon-m-signal')
                ->color('success'),

            /*
            |--------------------------------------------------------------------------
            | LIVE CONNECTIONS
            |--------------------------------------------------------------------------
            */

            Stat::make('Live Connections', number_format($liveConnections))
                ->description('Active VPN sessions')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('success'),

            /*
            |--------------------------------------------------------------------------
            | SERVERS ONLINE
            |--------------------------------------------------------------------------
            */

            Stat::make(
                'Servers Online',
                "{$serversOnline} / {$serversTotal}"
            )
                ->description(
                    $serversOffline > 0
                        ? "{$serversOffline} offline"
                        : 'All servers healthy'
                )
                ->descriptionIcon(
                    $serversOffline > 0
                        ? 'heroicon-m-exclamation-triangle'
                        : 'heroicon-m-check-circle'
                )
                ->color(
                    $serversOffline > 0
                        ? 'warning'
                        : 'success'
                ),

            /*
            |--------------------------------------------------------------------------
            | ACTIVE CUSTOMERS
            |--------------------------------------------------------------------------
            */

            Stat::make(
                'Active VPN Users',
                number_format($vpnUsersActive)
            )
                ->description(
                    number_format($vpnUsersTotal) . ' total users'
                )
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),

            /*
            |--------------------------------------------------------------------------
            | CONNECTIONS TODAY
            |--------------------------------------------------------------------------
            */

            Stat::make(
                'Connections Today',
                number_format($connectionsToday)
            )
                ->description('New sessions started today')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('primary'),

            /*
            |--------------------------------------------------------------------------
            | STALE CONNECTIONS
            |--------------------------------------------------------------------------
            */

            Stat::make(
                'Stale Connections',
                number_format($staleConnections)
            )
                ->description(
                    $staleConnections > 0
                        ? 'Connections require cleanup'
                        : 'No stale sessions'
                )
                ->descriptionIcon(
                    $staleConnections > 0
                        ? 'heroicon-m-x-circle'
                        : 'heroicon-m-check-badge'
                )
                ->color(
                    $staleConnections > 0
                        ? 'danger'
                        : 'success'
                ),

        ];
    }
}