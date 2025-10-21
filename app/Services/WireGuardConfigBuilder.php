<?php

namespace App\Services;

use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Support\Str;

class WireGuardConfigBuilder
{
    /**
     * Build a WireGuard .conf text for a given user+server.
     * - Uses server.wg_endpoint_host OR falls back to server.ip_address
     * - Sets DNS to WG private resolver (10.66.66.1) by default
     */
    public static function build(VpnUser $user, VpnServer $server): string
    {
        // Sanity checks for client materials
        if (blank($user->wireguard_private_key) || blank($user->wireguard_address)) {
            throw new \RuntimeException('User is missing WireGuard keys or address.');
        }

        // Endpoint host: prefer explicit WG endpoint, then the server IP
        $endpointHost = $server->wg_endpoint_host ?: $server->ip_address;
        if (blank($endpointHost)) {
            throw new \RuntimeException('Server is missing WireGuard endpoint host/IP.');
        }
        $endpointPort = (int) ($server->wg_port ?: 51820);

        // Server public key is required
        if (blank($server->wg_public_key)) {
            throw new \RuntimeException('Server is missing WireGuard public key.');
        }

        // Normalize client Address (ensure /32 for IPv4)
        $addr = trim($user->wireguard_address);
        $address = Str::contains($addr, '/') ? $addr : ($addr . '/32');

        // DNS: prefer a WG-local resolver, then fallback
        // If you store a specific column (e.g. wg_dns_ip), prefer that.
        $dns = $server->wg_dns_ip // optional column if you add it
            ?? '10.66.66.1'      // your private Unbound on wg0
            ?? ($server->dns ?: '1.1.1.1, 1.0.0.1');

        $endpoint = "{$endpointHost}:{$endpointPort}";

        return <<<CONF
[Interface]
PrivateKey = {$user->wireguard_private_key}
Address = {$address}
DNS = {$dns}

[Peer]
PublicKey = {$server->wg_public_key}
AllowedIPs = 0.0.0.0/0, ::/0
Endpoint = {$endpoint}
PersistentKeepalive = 25
CONF;
    }

    /**
     * Optional: a consistent filename, e.g. "alice-Germany.conf"
     */
    public static function suggestedFilename(VpnUser $user, VpnServer $server): string
    {
        $name = Str::slug($server->name ?? 'server', '-');
        $userSlug = Str::slug($user->username ?? 'user', '-');
        return "{$userSlug}-{$name}.conf";
    }
}