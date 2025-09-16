<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VpnUser;
use App\Services\VpnConfigBuilder;

class MobileProfileController extends Controller
{
    /**
     * GET /api/profiles  (auth:sanctum)
     * Basic account info + assigned servers.
     */
    public function index(Request $request)
    {
        /** @var VpnUser $user */
        $user = $request->user()->loadMissing('vpnServers');

        $servers = $user->vpnServers->map(function ($s) {
            return [
                'id'   => $s->id,
                'name' => $s->name ?? ('Server '.$s->id),
                'ip'   => $s->ip_address ?? $s->ip ?? null,
            ];
        });

        return response()->json([
            'id'       => $user->id,
            'username' => $user->username,
            'expires'  => $user->expires_at,
            'max_conn' => (int) $user->max_connections,
            'servers'  => $servers,
        ]);
    }

    /**
     * GET /api/profiles/{user}?server_id=112  (auth:sanctum)
     * Returns a ready-to-import .ovpn for the selected/first server.
     */
    public function show(Request $request, VpnUser $user)
    {
        // only allow the logged-in vpn_user to fetch their own profile
        if ($request->user()->id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // choose server: ?server_id=112 or fall back to first assigned
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
            return response(
                "Could not build config from {$server->ip_address}: ".$e->getMessage(),
                502
            );
        }

        $filename = "aio-{$user->username}-{$server->id}.ovpn";

        return response($config, 200, [
            'Content-Type'        => 'application/x-openvpn-profile',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-store',
        ]);
    }
}