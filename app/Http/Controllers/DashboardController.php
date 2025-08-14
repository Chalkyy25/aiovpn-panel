<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VpnUser;
use App\Models\VpnServer;

class DashboardController extends Controller
{
    public function __invoke()
    {
        // TODO: replace with your real sources for online/active metrics
        $metrics = [
            'online_users'       => 0, // e.g., VpnUser::where('status','online')->count()
            'active_connections' => 0, // your real-time source
            'active_servers'     => VpnServer::query()->count() ?? 0,
            'avg_time'           => '0m', // compute from session logs if available
        ];

        $users = VpnUser::query()
            ->with('vpnServers')
            ->latest('id')
            ->limit(10)
            ->get();

        return view('admin.dashboard', compact('metrics', 'users'));
    }
}