<?php

namespace App\Http\Controllers;

use App\Models\VpnServer;
use App\Models\VpnUser;
use App\Services\WireGuardService;
use Illuminate\Http\Request;

class WireGuardConfigController extends Controller
{
    public function __construct(
        protected WireGuardService $wg
    ) {}

    /**
     * List WireGuard-capable servers (for app server picker).
     */
    public function servers(Request $request)
    {
        $authUser = $request->user();
        if (!($authUser instanceof VpnUser)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // If you later switch back to per-user server assignments,
        // you can change this to $authUser->vpnServers() again.
        $servers = VpnServer::query()
            ->where('enabled', true)
            ->whereNotNull('wg_public_key')
            ->whereNotNull('wg_endpoint_host')
            ->orderBy('country_code')
            ->orderBy('city')
            ->get()
            ->map(fn (VpnServer $s) => [
                'id'          => (int) $s->id,
                'name'        => $s->name,
                'ip'          => $s->ip_address,
                'country'     => $s->country_code,
                'city'        => $s->city,
                'label'       => $s->display_location,
                'endpoint'    => $s->wgEndpoint(),
                'port'        => $s->wg_port ?: 51820,
                'subnet'      => $s->wg_subnet,
                'tags'        => $s->tags,
            ]);

        return response()->json(['data' => $servers]);
    }

    /**
     * Return a ready-to-import WireGuard config for the authenticated user.
     *
     * GET /api/wg/config?server_id=123
     */
    public function config(Request $request)
    {
        $authUser = $request->user();
        if (!($authUser instanceof VpnUser)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (!$authUser->is_active || $authUser->isExpired()) {
            return response()->json(['error' => 'Account inactive or expired'], 403);
        }

        $data = $request->validate([
            'server_id' => ['required', 'integer'],
        ]);

        $serverId = (int) $data['server_id'];

        /** @var VpnServer $server */
        $server = VpnServer::query()
            ->where('enabled', true)
            ->whereKey($serverId)
            ->firstOrFail();

        if (!$server->hasWireGuard()) {
            return response()->json(['error' => 'Server has no WireGuard enabled'], 422);
        }

        $vpnUser = $authUser;

        // Ensure peer exists (or create + push it)
        $peer = $this->wg->ensurePeerForUser($server, $vpnUser);

        $config = $this->wg->buildClientConfig($server, $peer);

        return response($config, 200, [
            'Content-Type'        => 'text/plain',
            'Content-Disposition' => 'attachment; filename="aiovpn-wg-' . $server->id . '.conf"',
        ]);
    }
}
