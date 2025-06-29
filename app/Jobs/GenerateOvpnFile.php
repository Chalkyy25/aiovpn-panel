<?php

namespace App\Jobs;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateOvpnFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client->load('vpnServer');
    }

    public function handle(): void
    {
        $server = $this->client->vpnServer;

        if (!$server) {
            Log::error("❌ No VPN server assigned for client {$this->client->username}");
            return;
        }

        $sshUser = 'root';
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        $ip = $server->ip_address;

        Log::info("🔑 Generating embedded .ovpn for {$this->client->username} on {$server->name}");

        // 🔹 Fetch CA cert
        $ca = $this->fetchRemoteFile($sshKey, $sshUser, $ip, '/etc/openvpn/ca.crt', 'CA cert');
        if (!$ca) return;

        // 🔹 Fetch TLS auth key
        $ta = $this->fetchRemoteFile($sshKey, $sshUser, $ip, '/etc/openvpn/ta.key', 'TLS auth key');
        if (!$ta) return;

        // 🔹 Fetch client cert
        $cert = $this->fetchRemoteFile($sshKey, $sshUser, $ip, "/etc/openvpn/easy-rsa/pki/issued/{$this->client->username}.crt", 'Client cert');
        if (!$cert) return;

        // 🔹 Fetch client key
        $key = $this->fetchRemoteFile($sshKey, $sshUser, $ip, "/etc/openvpn/easy-rsa/pki/private/{$this->client->username}.key", 'Client key');
        if (!$key) return;

        // 🔹 Load template
        $templatePath = 'ovpn_templates/client.ovpn';
        if (!Storage::exists($templatePath)) {
            Log::error("❌ Missing OVPN template at {$templatePath}");
            return;
        }
        $template = Storage::get($templatePath);

        // 🔹 Replace {{SERVER_IP}}
        $config = str_replace('{{SERVER_IP}}', $ip, $template);

        // 🔹 Embed all certificates and keys
$config = str_replace(
    ['{{CA_CERT}}', '{{CLIENT_CERT}}', '{{CLIENT_KEY}}', '{{TLS_AUTH}}'],
    [$caBlock, $certBlock, $keyBlock, $tlsBlock],
    $template
);
        // 🔹 Save final .ovpn file
        $fileName = "ovpn_configs/{$server->name}_{$this->client->username}.ovpn";
        Storage::put($fileName, $config);

        Log::info("✅ Embedded .ovpn generated at storage/app/{$fileName}");
    }

    private function fetchRemoteFile(string $sshKey, string $sshUser, string $ip, string $remotePath, string $label): ?string
    {
        $output = [];
        exec("ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'cat {$remotePath}'", $output, $status);

        if ($status !== 0 || empty($output)) {
            Log::error("❌ Failed to fetch {$label} from {$ip} (status {$status})");
            return null;
        }

        return implode("\n", $output);
    }
}
