<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VpnConnection;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardController extends Controller
{
    public function __invoke()
    {
        $metrics = Cache::remember(
            'admin.dashboard.metrics',
            now()->addSeconds(15),
            function () {

                // Users
                $totalUsers    = User::count();
                $activeUsers   = User::where('is_active', true)->count();
                $totalVpnUsers = VpnUser::count();

                // Live VPN users
                $activeVpnUsers = VpnConnection::live()
                    ->distinct('vpn_user_id')
                    ->count('vpn_user_id');

                // Live connections
                $activeConnections = VpnConnection::live()->count();

                // Servers
                $totalServers = VpnServer::count();

                $onlineServers = VpnConnection::live()
                    ->distinct('vpn_server_id')
                    ->count('vpn_server_id');

                $offlineServers = max($totalServers - $onlineServers, 0);

                // Average connection duration
                $avgSeconds = (int) (
                    VpnConnection::live()
                        ->select(
                            DB::raw(
                                'AVG(TIMESTAMPDIFF(SECOND, connected_at, NOW())) as avg_sec'
                            )
                        )
                        ->value('avg_sec') ?? 0
                );

                $avgTime = $avgSeconds >= 3600
                    ? sprintf(
                        '%dh %dm',
                        intdiv($avgSeconds, 3600),
                        intdiv($avgSeconds % 3600, 60)
                    )
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
            }
        );

        return view('dashboards.admin', $metrics);
    }
}