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
 *  - snapshot() is cached for 5 seconds so all widgets share the same
 *    consistent values.
 */
class DashboardStatsService
{
    /**
     * In-memory request lifecycle snapshot cache.
     */
    protected ?array $snapshot = null;

    public function snapshot(): array
    {
        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate cache lookups in same request
        |--------------------------------------------------------------------------
        */

        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        /*
        |--------------------------------------------------------------------------
        | Global admin dashboard snapshot
        |--------------------------------------------------------------------------
        |
        | This cache is intentionally global/shared for admin dashboard widgets.
        | Reseller dashboards use their own scoped queries separately.
        | Future architecture may introduce tenant/node scoped snapshots.
        |
        */

        return $this->snapshot = Cache::remember(
            'dashboard:snapshot:global',
            now()->addSeconds(5),

            function () {

                $now = now();

                /*
                |--------------------------------------------------------------------------
                | Canonical live connection query
                |--------------------------------------------------------------------------
                */

                $liveConnectionsQuery = VpnConnection::query()
                    ->live($now);

                /*
                |--------------------------------------------------------------------------
                | Total live sessions/devices
                |--------------------------------------------------------------------------
                */

                $liveConnections = (clone $liveConnectionsQuery)
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | Distinct VPN users with at least one live session
                |--------------------------------------------------------------------------
                */

                $usersOnline = (clone $liveConnectionsQuery)
                    ->distinct('vpn_user_id')
                    ->count('vpn_user_id');

                /*
                |--------------------------------------------------------------------------
                | Stale sessions
                |--------------------------------------------------------------------------
                */

                $staleConnections = VpnConnection::query()
                    ->stale($now)
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | TODO
                |--------------------------------------------------------------------------
                |
                | Move server online detection to a canonical
                | VpnServer::online() scope or freshness-based runtime
                | evaluation instead of relying on persisted is_online.
                |
                */

                $serversOnline = VpnServer::query()
                    ->where('is_online', true)
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | Server totals
                |--------------------------------------------------------------------------
                */

                $serversTotal = VpnServer::query()
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | User totals
                |--------------------------------------------------------------------------
                */

                $totalUsers = VpnUser::query()
                    ->count();

                $enabledUsers = VpnUser::query()
                    ->where('is_active', true)
                    ->count();

                /*
                |--------------------------------------------------------------------------
                | Distinct users active today
                |--------------------------------------------------------------------------
                |
                | This uses last_seen_at, meaning:
                | "users active today"
                | NOT:
                | "users who first connected today"
                |
                */

                $usersToday = VpnConnection::query()
                    ->whereDate('last_seen_at', today())
                    ->distinct('vpn_user_id')
                    ->count('vpn_user_id');

                return [

                    /*
                    |--------------------------------------------------------------------------
                    | Connection metrics
                    |--------------------------------------------------------------------------
                    */

                    // Distinct users with a live session right now.
                    'users_online' => $usersOnline,

                    // Total live connection rows (devices/sessions).
                    'live_connections' => $liveConnections,

                    // Sessions considered stale/offline.
                    'stale_connections' => $staleConnections,

                    /*
                    |--------------------------------------------------------------------------
                    | Server metrics
                    |--------------------------------------------------------------------------
                    */

                    'servers_online' => $serversOnline,
                    'servers_total'  => $serversTotal,

                    /*
                    |--------------------------------------------------------------------------
                    | User metrics
                    |--------------------------------------------------------------------------
                    */

                    'total_users'   => $totalUsers,
                    'enabled_users' => $enabledUsers,
                    'users_today'   => $usersToday,

                    /*
                    |--------------------------------------------------------------------------
                    | Metadata
                    |--------------------------------------------------------------------------
                    */

                    'generated_at' => $now,
                ];
            }
        );
    }
}