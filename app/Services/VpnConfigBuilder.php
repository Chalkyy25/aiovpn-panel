<?php

namespace App\Services;

use App\Models\VpnUser;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VpnConfigBuilder
{
    /**
     * Generate OpenVPN configs for all servers assigned to the user.
     */
    public static function generate(VpnUser $vpnUser): array
    {
        $generatedFiles = [];

        foreach ($vpnUser->vpnServers as $server) {
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

            // ✅ Create filename based on server name + username
            $safeServerName = str_replace([' ', '(', ')'], ['_', '', ''], $server->name);
            $fileName = "{$safeServerName}_{$vpnUser->username}.ovpn";

            Storage::disk('local')->put("configs/{$fileName}", $config);
            $generatedFiles[] = storage_path("app/configs/{$fileName}");

            Log::info("✅ OpenVPN config generated: {$fileName}");
        }

        return $generatedFiles;
    }

    /**
     * Generate WireGuard config for the user.
     */
    public static function generateWireGuard(VpnUser $vpnUser): string
    {
        // Assuming only one server for WireGuard per user
        $server = $vpnUser->vpnServers->first();

        if (!$server) {
            Log::warning("⚠️ No server assigned to user {$vpnUser->username} for WireGuard config.");
            return '';
        }

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

        $fileName = "{$vpnUser->username}.conf";
        Storage::disk('local')->put("configs/{$fileName}", $config);

        Log::info("✅ WireGuard config generated: {$fileName}");

        return storage_path("app/configs/{$fileName}");
    }
}
