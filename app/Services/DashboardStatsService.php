<?php

namespace App\Services;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Cache;

class DashboardStatsService
{
    public function snapshot(): array
    {
        return Cache::remember(
            'dashboard:snapshot',
            now()->addSeconds(5),
            function () {

                $now = now();

                $liveConnectionsQuery = VpnConnection::query()
                    ->live($now);

                $liveConnections = (clone $liveConnectionsQuery)->count();

                $staleConnections = VpnConnection::query()
                    ->stale($now)
                    ->count();

                $serversOnline = VpnServer::query()
                    ->where('is_online', true)
                    ->count();

                $serversTotal = VpnServer::query()
                    ->count();

                $totalUsers = VpnUser::query()
                    ->count();

                $enabledUsers = VpnUser::query()
                    ->where('is_active', true)
                    ->count();

                $usersToday = VpnConnection::query()
                    ->whereDate('last_seen_at', today())
                    ->distinct('vpn_user_id')
                    ->count('vpn_user_id');

                return [
                    'users_online'       => $liveConnections,
                    'live_connections'   => $liveConnections,
                    'stale_connections' => $staleConnections,

                    'servers_online'    => $serversOnline,
                    'servers_total'     => $serversTotal,

                    'total_users'       => $totalUsers,
                    'enabled_users'     => $enabledUsers,

                    'users_today'       => $usersToday,

                    'generated_at'      => $now,
                ];
            }
        );
    }
}