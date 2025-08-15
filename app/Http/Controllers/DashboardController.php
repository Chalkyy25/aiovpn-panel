<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Models\VpnUserConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function __invoke()
    {
        // cache briefly to avoid hammering DB
        $metrics = Cache::remember('admin.dashboard.metrics', now()->addSeconds(15), function () {
            // Users
            $totalUsers     = User::count();
            $activeUsers    = User::where('is_active', true)->count(); // if you don’t have this column, set to 0 or remove
            $totalVpnUsers  = VpnUser::count();

            // “Active” VPN users = distinct users with a live connection
            $activeVpnUsers = VpnUserConnection::connected()
                ->whereNull('disconnected_at')
                ->distinct('vpn_user_id')
                ->count('vpn_user_id');

            // Connections
            $activeConnections = VpnUserConnection::connected()
                ->whereNull('disconnected_at')
                ->count();

            // Servers: online = servers that currently have at least one live connection
            $totalServers  = VpnServer::count();
            $onlineServers = VpnUserConnection::connected()
                ->whereNull('disconnected_at')
                ->distinct('vpn_server_id')
                ->count('vpn_server_id');
            $offlineServers = max($totalServers - $onlineServers, 0);

            // Avg connection time (seconds) for currently connected sessions
            $avgSeconds = (int) (VpnUserConnection::connected()
                ->whereNull('disconnected_at')
                ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, connected_at, NOW())) as avg_sec'))
                ->value('avg_sec') ?? 0);

            $avgTime = $avgSeconds >= 3600
                ? sprintf('%dh %dm', intdiv($avgSeconds, 3600), intdiv($avgSeconds % 3600, 60))
                : sprintf('%dm', intdiv($avgSeconds, 60));

            // Roles
            $totalResellers = User::where('role', 'reseller')->count();
            $totalClients   = User::where('role', 'client')->count();

            return [
                'totalUsers'        => $totalUsers,
                'activeUsers'       => $activeUsers,
                'totalVpnUsers'     => $totalVpnUsers,
                'activeVpnUsers'    => $activeVpnUsers,
                'activeConnections' => $activeConnections,
                'totalResellers'    => $totalResellers,
                'totalClients'      => $totalClients,
                'totalServers'      => $totalServers,
                'onlineServers'     => $onlineServers,
                'offlineServers'    => $offlineServers,
                'avgTime'           => $avgTime,
            ];
        });

        return view('dashboards.admin', $metrics);
    }
}