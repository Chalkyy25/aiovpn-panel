<?php

namespace App\Services;

use App\Models\VpnServer;
use App\Models\VpnUser;
use Illuminate\Support\Str;

class WireGuardConfigBuilder
{
    /**
     * Build a WireGuard .conf text for a given user+server.
     */
    public static function build(VpnUser $user, VpnServer $server): string
    {
        // Basic sanity checks
        if (blank($user->wireguard_private_key) || blank($user->wireguard_address)) {
            throw new \RuntimeException('User is missing WireGuard keys or address.');
        }
        if (blank($server->wg_public_key) || blank($server->wg_endpoint_host)) {
            throw new \RuntimeException('Server is missing WireGuard public key or endpoint.');
        }

        $endpoint = $server->wg_endpoint_host.':'.(int)($server->wg_port ?: 51820);
        $dns      = $server->dns ?: '1.1.1.1, 1.0.0.1';

        // ensure address has /32 if it looks like simple IPv4
        $addr = trim($user->wireguard_address);
        if (Str::contains($addr, '/')) {
            $address = $addr;
        } else {
            $address = $addr.'/32';
        }

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
}