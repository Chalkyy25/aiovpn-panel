<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class DashboardController extends Controller
{
    public function __invoke()
    {
        // Cache briefly to avoid hammering DB (tune as needed)
        $metrics = Cache::remember('admin.dashboard.metrics', now()->addSeconds(15), function () {
            $totalUsers     = User::count();
            $activeUsers    = User::where('is_active', true)->count();
            $totalVpnUsers  = VpnUser::count();
            $activeVpnUsers = VpnUser::has('vpnServers')->count();
            $totalResellers = User::where('role', 'reseller')->count();
            $totalClients   = User::where('role', 'client')->count();

            // ---- Server stats (robust to different schemas)
            $totalServers = VpnServer::count();

            $onlineByStatus    = VpnServer::where('status', 'online')->count();      // if you have a 'status' column
            $onlineByActive    = VpnServer::where('is_active', true)->count();       // or a boolean flag
            $onlineByHeartbeat = VpnServer::whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', Carbon::now()->subMinutes(5))->count(); // or heartbeat

            $onlineServers  = max($onlineByStatus, $onlineByActive, $onlineByHeartbeat);
            $offlineServers = max($totalServers - $onlineServers, 0);

            return [
                'totalUsers'     => $totalUsers,
                'activeUsers'    => $activeUsers,
                'totalVpnUsers'  => $totalVpnUsers,
                'activeVpnUsers' => $activeVpnUsers,
                'totalResellers' => $totalResellers,
                'totalClients'   => $totalClients,
                'totalServers'   => $totalServers,
                'onlineServers'  => $onlineServers,
                'offlineServers' => $offlineServers,
            ];
        });

        // pass just what the Blade expects
        return view('dashboards.admin', $metrics);
    }
}