<?php

namespace App\Filament\Widgets;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminStats extends BaseWidget
{
    protected function getStats(): array
    {
        $totalServers = VpnServer::count();

        // Use DB-backed status, not the computed accessor.
        // Adjust 'online' if you store a different value.
        $onlineServers = VpnServer::where('status', 'online')->count();

        $activeConnections = VpnUserConnection::where('is_connected', true)->count();

        $usersOnline = VpnUserConnection::where('is_connected', true)
            ->distinct('vpn_user_id')
            ->count('vpn_user_id');

        $totalVpnUsers = VpnUser::count();

        return [
            Stat::make('Total Servers', $totalServers)->color('primary'),
            Stat::make('Online Servers', $onlineServers)->color('success'),
            Stat::make('Active Connections', $activeConnections)->color('warning'),
            Stat::make('Users Online', $usersOnline)->color('success'),
            Stat::make('Total VPN Users', $totalVpnUsers)->color('info'),
        ];
    }
}
