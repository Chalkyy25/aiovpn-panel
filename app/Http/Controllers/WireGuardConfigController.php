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
        $serverId = (int) $request->query('server_id');

        if (!$serverId) {
            return response()->json(['error' => 'server_id is required'], 422);
        }

        $server = VpnServer::findOrFail($serverId);

        if (!$server->hasWireGuard()) {
            return response()->json(['error' => 'Server has no WireGuard enabled'], 422);
        }

        // Resolve the authenticated principal to a concrete VpnUser model.
        // This is future-proof to work whether the request is authenticated as
        // a VpnUser (client app) or as a different User type (e.g., admin).
        $vpnUser = null;

        if ($authUser instanceof VpnUser) {
            $vpnUser = $authUser;
        } else {
            // Try explicit username from query first (if provided by client)
            $username = (string) $request->query('username', '');

            if ($username !== '') {
                $vpnUser = VpnUser::where('username', $username)->first();
            }

            // Fallbacks: try common mappings from the authenticated user
            if (!$vpnUser && method_exists($authUser, 'vpnUser')) {
                // Relation on the auth model
                $vpnUser = $authUser->vpnUser; // may be null
            }

            if (!$vpnUser && property_exists($authUser, 'username') && !empty($authUser->username)) {
                $vpnUser = VpnUser::where('username', $authUser->username)->first();
            }
        }

        if (!$vpnUser instanceof VpnUser) {
            return response()->json([
                'error' => 'Unable to resolve VPN user for this request',
                'hint'  => 'Authenticate as a VpnUser or provide ?username=...',
            ], 422);
        }

        // Ensure peer exists (or create + push it)
        $peer = $this->wg->ensurePeerForUser($server, $vpnUser);

        $config = $this->wg->buildClientConfig($server, $peer);

        return response($config, 200, [
            'Content-Type'        => 'text/plain',
            'Content-Disposition' => 'attachment; filename="aiovpn-wg-' . $server->id . '.conf"',
        ]);
    }
}
