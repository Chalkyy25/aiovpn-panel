<?php

namespace App\Filament\Widgets;

use App\Services\DashboardStatsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStats extends BaseWidget
{
    protected static ?int $sort = 1;

    // All stats widgets share the same 10-second poll so the dashboard
    // refreshes consistently from the DashboardStatsService cache.
    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        // Dashboard metrics are centralised in DashboardStatsService.
        // Do NOT add ad-hoc DB queries here; extend the service instead.
        $stats = app(DashboardStatsService::class)->snapshot();

        $serversOffline = max(0, $stats['servers_total'] - $stats['servers_online']);

        return [

            /*
            |--------------------------------------------------------------------------
            | ONLINE USERS
            |--------------------------------------------------------------------------
            */

            Stat::make('Users Online', number_format($stats['users_online']))
                ->description('Currently connected users')
                ->descriptionIcon('heroicon-m-signal')
                ->color('success'),

            /*
            |--------------------------------------------------------------------------
            | LIVE CONNECTIONS
            |--------------------------------------------------------------------------
            */

            Stat::make('Live Connections', number_format($stats['live_connections']))
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
                "{$stats['servers_online']} / {$stats['servers_total']}"
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
                'Total VPN Users',
                number_format($stats['total_users'])
            )
                ->description(
                    number_format($stats['enabled_users']) . ' enabled'
                )
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),

            /*
            |--------------------------------------------------------------------------
            | CONNECTIONS TODAY
            |--------------------------------------------------------------------------
            */

            Stat::make(
                    'Users Connected Today',
                    number_format($stats['users_today'])
                )
                    ->description('Unique VPN users active today')
                    ->descriptionIcon('heroicon-m-bolt')
                    ->color('primary'),

            /*
            |--------------------------------------------------------------------------
            | STALE CONNECTIONS
            |--------------------------------------------------------------------------
            */

            Stat::make(
                'Stale Connections',
                number_format($stats['stale_connections'])
            )
                ->description(
                    $stats['stale_connections'] > 0
                        ? 'Connections require cleanup'
                        : 'No stale sessions'
                )
                ->descriptionIcon(
                    $stats['stale_connections'] > 0
                        ? 'heroicon-m-x-circle'
                        : 'heroicon-m-check-badge'
                )
                ->color(
                    $stats['stale_connections'] > 0
                        ? 'danger'
                        : 'success'
                ),

        ];
    }
}