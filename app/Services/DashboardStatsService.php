<?php

namespace App\Services;

use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Cache;

/**
 * DashboardStatsService is the single authoritative source for all
 * dashboard/admin metrics. Every stats widget must read from snapshot()
 * rather than issuing its own DB queries, so counts stay consistent
 * and the live() scope logic is never duplicated.
 *
 * Architecture notes:
 *  - WireGuard "online" state is inferred from last_seen_at freshness
 *    (VpnConnection::WIREGUARD_STALE_SECONDS), not from a persistent flag.
 *  - vpn_servers.online_users is a legacy write-through cache and must NOT
 *    be used for dashboard counts.
 *  - snapshot() is cached for 5 s so all widgets in the same request
 *    lifecycle share the same consistent values.
 */
class DashboardStatsService
{
    public function snapshot(): array
    {
        return Cache::remember(
            'dashboard:snapshot',
            now()->addSeconds(5),
            function () {

                $now = now();

                // Build once; clone for each derived count to avoid re-querying.
                $liveConnectionsQuery = VpnConnection::query()->live($now);

                $liveConnections = (clone $liveConnectionsQuery)->count();

                // Distinct VPN users with at least one live session.
                $usersOnline = (clone $liveConnectionsQuery)
                    ->distinct('vpn_user_id')
                    ->count('vpn_user_id');

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
                    // Distinct users with a live session right now.
                    'users_online'      => $usersOnline,
                    // Total live connection rows (one user may have many).
                    'live_connections'  => $liveConnections,
                    'stale_connections' => $staleConnections,

                    'servers_online'    => $serversOnline,
                    'servers_total'     => $serversTotal,

                    'total_users'       => $totalUsers,
                    'enabled_users'     => $enabledUsers,

                    // Distinct users seen today (by last_seen_at date).
                    'users_today'       => $usersToday,

                    'generated_at'      => $now,
                ];
            }
        );
    }
}