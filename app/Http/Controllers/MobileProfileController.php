<?php

namespace App\Http\Controllers;

use App\Services\VpnConfigBuilder;
use Illuminate\Http\Request;
use App\Models\VpnUser;

public function show(Request $request, VpnUser $user)
{
    // Only allow the logged-in vpn_user to fetch their own profile
    if ($request->user()->id !== $user->id) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }

    // Choose server: ?server_id=112 or fall back to first assigned
    $serverId = (int) $request->query('server_id', 0);
    $server = $serverId
        ? $user->vpnServers()->where('vpn_servers.id', $serverId)->first()
        : $user->vpnServers()->first();

    if (!$server) {
        return response("No VPN server assigned to this user", 404);
    }

    try {
        $config = VpnConfigBuilder::generateOpenVpnConfigString($user, $server);
    } catch (\Throwable $e) {
        // Return a concise error that still helps you debug
        return response(
            "Could not build config from {$server->ip_address}: ".$e->getMessage(),
            502
        );
    }

    $filename = "aio-{$user->username}-{$server->id}.ovpn";
    return response($config, 200, [
        'Content-Type'        => 'application/x-openvpn-profile',
        'Content-Disposition' => "attachment; filename=\"$filename\"",
        'Cache-Control'       => 'no-store',
    ]);
}