<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\VpnUser;
use App\Models\VpnServer;
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
        $raw = strtolower((string) $s->protocol); // e.g. "udp", "tcp", "wireguard"

        // Normalise the protocol type for Android app
        $type      = in_array($raw, ['udp', 'tcp']) ? 'openvpn' : ($raw ?: 'openvpn');
        $transport = in_array($raw, ['udp', 'tcp']) ? $raw : null;

        // Geo fields from vpn_servers
        $countryCode = $s->country_code ?: null;
        $city        = $s->city ?: null;

        return [
            'id'           => (int) $s->id,
            'name'         => $s->name ?? ('Server ' . $s->id),
            'ip'           => $s->ip_address ?? $s->ip ?? null,
            'protocol'     => $type,        // "openvpn" or "wireguard"
            'transport'    => $transport,   // "udp" / "tcp" / null

            // new geo fields for the app
            'country_code' => $countryCode, // "GB", "DE", "NL", ...
            'city'         => $city,        // "London", "Frankfurt am Main", ...

            // optional friendly country name – keep it simple for now
            'country_name' => $countryCode
                ? $this->mapCountryName($countryCode)
                : null,
        ];
    })->values();

    return response()->json([
        'id'       => (int) $user->id,
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
        if ((int) $request->user()->id !== (int) $user->id) {
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

    /**
     * GET /api/ovpn?user_id=7&server_id=99  (auth:sanctum)
     * Minimal, mobile-friendly endpoint that returns raw .ovpn text.
     * Matches what your Android app expects.
     */
    public function ovpn(Request $request)
    {
        $data = $request->validate([
            'user_id'   => 'required|integer',
            'server_id' => 'required|integer',
            'variant'   => 'nullable|string|in:unified,stealth,udp', // Support variant selection
        ]);

        /** @var VpnUser|null $authed */
        $authed = $request->user();
        if (!$authed || (int) $authed->id !== (int) $data['user_id']) {
            return response('Unauthorized', 401);
        }

        /** @var VpnUser $vpnUser */
        $vpnUser = VpnUser::findOrFail($data['user_id']);
        /** @var VpnServer $vpnServer */
        $vpnServer = VpnServer::findOrFail($data['server_id']);

        // Ensure the user is assigned to this server
        $assigned = $vpnUser->vpnServers()->whereKey($vpnServer->id)->exists();
        if (!$assigned) {
            return response('Server not assigned to this user.', 403);
        }

        // Default to unified (stealth + fallback) for best ISP bypass
        $variant = $data['variant'] ?? 'unified';

        try {
            $ovpn = VpnConfigBuilder::generateOpenVpnConfigString($vpnUser, $vpnServer, $variant);
        } catch (\Throwable $e) {
            Log::error('OVPN build failed', [
                'u' => $vpnUser->id, 's' => $vpnServer->id, 'variant' => $variant, 'err' => $e->getMessage(),
            ]);
            return response('Failed to generate config', 502);
        }

        // quick sanity checks (also visible in server logs)
        $checks = [
            'bytes'      => strlen($ovpn),
            'has_remote' => (bool) preg_match('/^remote\s+\S+\s+\d+/mi', $ovpn),
            'has_ca'     => str_contains($ovpn, '<ca>'),
            'has_ta'     => str_contains($ovpn, '<tls-auth>'),
            'key_dir'    => str_contains($ovpn, 'key-direction'),
            'auth_up'    => str_contains($ovpn, 'auth-user-pass'),
        ];
        Log::info('OVPN served', ['u' => $vpnUser->id, 's' => $vpnServer->id] + $checks);

        return response($ovpn, 200, [
            'Content-Type'  => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }
    
    private function mapCountryName(?string $code): ?string
{
    if (!$code) {
        return null;
    }

    $map = [
        'GB' => 'United Kingdom',
        'UK' => 'United Kingdom',
        'DE' => 'Germany',
        'ES' => 'Spain',
        'NL' => 'Netherlands',
        'US' => 'United States',
        'FR' => 'France',
        // extend as needed
    ];

    $code = strtoupper($code);
    return $map[$code] ?? $code;
}
}