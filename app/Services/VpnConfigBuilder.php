<?php

namespace App\Services;

use App\Models\VpnUser;
use Illuminate\Support\Facades\Storage;

class VpnConfigBuilder
{
    /**
     * Generate OpenVPN config with embedded credentials.
     */
    public static function generate(VpnUser $vpnUser): string
    {
        $server = $vpnUser->vpnServer;

        $caCert = trim(Storage::disk('local')->get("certs/{$server->id}/ca.crt"));
        $tlsKey = trim(Storage::disk('local')->get("certs/{$server->id}/ta.key"));

        $config = <<<EOL
client
dev tun
proto udp
remote {$server->ip_address} 1194
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
auth-user-pass
auth SHA256
cipher AES-256-CBC
verb 3
<ca>
$caCert
</ca>
<tls-auth>
$tlsKey
</tls-auth>
key-direction 1

# Embedded user-pass
<auth-user-pass>
{$vpnUser->username}
{$vpnUser->password}
</auth-user-pass>
EOL;

        $path = "configs/{$vpnUser->id}.ovpn";
        Storage::disk('local')->put($path, $config);

        return storage_path("app/{$path}");
    }

    /**
     * Generate WireGuard config for the user.
     */
    public static function generateWireGuard(VpnUser $vpnUser): string
    {
        $server = $vpnUser->vpnServer;
        $serverPublicKey = trim(Storage::disk('local')->get("wireguard/{$server->id}/server_public_key"));
        $serverEndpoint = "{$server->ip_address}:51820";

        $config = <<<EOL
[Interface]
PrivateKey = {$vpnUser->wireguard_private_key}
Address = {$vpnUser->wireguard_address}
DNS = 1.1.1.1

[Peer]
PublicKey = {$serverPublicKey}
Endpoint = {$serverEndpoint}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
EOL;

        $path = "configs/{$vpnUser->id}.conf";
        Storage::disk('local')->put($path, $config);

        return storage_path("app/{$path}");
    }
}
