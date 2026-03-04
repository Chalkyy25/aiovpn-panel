<?php

namespace App\Filament\Widgets;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStats extends BaseWidget
{
    protected static ?int $sort = 3;
    protected static ?string $pollingInterval = '15s';

    protected int|string|array $columnSpan = [
        'default' => 1,
        'lg' => 3, // full-width row on desktop
    ];

    protected function getStats(): array
    {
        $now = now();

        $serversTotal = VpnServer::count();

        // “online” by your server heartbeat
        $serversOnline = VpnServer::where('is_online', 1)->count();

        $vpnUsersTotal = VpnUser::count();
        $vpnUsersActive = VpnUser::query()->where('is_active', true)->count();

        // Live connections: active + not stale (per protocol)
        $activeLive = VpnConnection::query()->live($now)->count();

        $usersOnline = VpnConnection::query()
            ->live($now)
            ->distinct('vpn_user_id')
            ->count('vpn_user_id');

        // “stale actives” = marked active but not seen recently
        $staleActives = VpnConnection::query()->stale($now)->count();

        return [
            Stat::make('Total Servers', $serversTotal)->color('gray'),
            Stat::make('Online Servers', $serversOnline)->color($serversOnline === $serversTotal ? 'success' : 'warning'),
            Stat::make('Total VPN Users', $vpnUsersTotal)->color('gray'),
            Stat::make('Active VPN Users', $vpnUsersActive)->color($vpnUsersActive === $vpnUsersTotal ? 'success' : 'warning'),
            Stat::make('Live Connections', $activeLive)->color('success'),
            Stat::make('Users Online', $usersOnline)->color('success'),
            Stat::make('Stale Actives', $staleActives)->color($staleActives > 0 ? 'danger' : 'gray')
                ->description('Active in DB but not seen recently'),
        ];
    }
}