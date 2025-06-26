<?php

namespace App\Services;

use App\Models\VpnUser;
use Illuminate\Support\Facades\Storage;

class VpnConfigBuilder
{
    public static function generate(VpnUser $vpnUser): string
    {
        $server = $vpnUser->vpnServer;

        // Define config structure
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
{CA_CERT}
</ca>
<tls-auth>
{TLS_KEY}
</tls-auth>
key-direction 1
EOL;

        // Read cert files from server (replace with SCP later if remote)
	$ca = trim(Storage::disk('local')->get("certs/{$server->id}/ca.crt"));
        $ta = trim(Storage::disk('local')->get("certs/{$server->id}/ta.key"));

        // Replace placeholders
        $config = str_replace('{CA_CERT}', trim($ca), $config);
        $config = str_replace('{TLS_KEY}', trim($ta), $config);

        // Add embedded credentials
        $config .= "\n\n# Embedded user-pass\n<auth-user-pass>\n{$vpnUser->username}\n{$vpnUser->password}\n</auth-user-pass>";

        // Save to file
        $path = "configs/{$vpnUser->id}.ovpn";
        Storage::disk('local')->put($path, $config);

        return storage_path("app/{$path}");
    }
}
