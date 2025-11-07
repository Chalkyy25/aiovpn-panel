<?php

namespace App\Http\Controllers;

use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Services\WireGuardConfigBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WireGuardConfigController extends Controller
{
    /**
     * GET /api/wg/servers
     * Return WG-capable servers assigned to the authenticated user.
     */
    public function servers(Request $request): JsonResponse
    {
        $user = $request->user();

        $servers = $user->vpnServers()
            ->whereNotNull('vpn_servers.wg_public_key')
            ->whereNotNull('vpn_servers.wg_port')
            ->get([
                'vpn_servers.id',
                'vpn_servers.name',
                'vpn_servers.ip_address',
                'vpn_servers.wg_endpoint_host',
                'vpn_servers.wg_port',
                'vpn_servers.dns',
            ])
            ->map(function (VpnServer $s) {
                return [
                    'id'       => $s->id,
                    'name'     => $s->name,
                    'endpoint' => ($s->wg_endpoint_host ?: $s->ip_address).':'.$s->wg_port,
                    'dns'      => $s->dns ?: '10.66.66.1',
                ];
            })
            ->values();

        return response()->json($servers);
    }

    /**
     * GET /api/wg/config?server_id=##
     * Return a WireGuard .conf for the authenticated user on the given server.
     */
    public function config(Request $request): Response
    {
        $data = $request->validate([
            'server_id' => 'required|integer',
        ]);

        $user = $request->user();

        /** @var VpnServer $server */
        $server = $user->vpnServers()
            ->where('vpn_servers.id', $data['server_id'])
            ->firstOrFail(); // 404 if not linked

        // Basic sanity checks
        abort_unless($server->wg_public_key && $server->wg_port, 400, 'Server not WireGuard-enabled');
        abort_unless(
            $user->wireguard_private_key && $user->wireguard_public_key && $user->wireguard_address,
            400,
            'User WireGuard keys/address missing'
        );

        // Build config text (uses your existing builder)
        $conf = WireGuardConfigBuilder::build($user, $server);

        Log::info('📄 WG config served', ['user' => $user->id, 'server' => $server->id]);

        $filename = 'aiovpn-'.$server->name.'.conf';

        return response($conf, 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
        ]);
    }
}