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

        Log::info("🔑 Generating .ovpn for client {$this->client->username} on server {$server->name} ({$ip})");

        // 🔹 Fetch CA cert
        $caContent = $this->fetchRemoteFile($sshKey, $sshUser, $ip, '/etc/openvpn/ca.crt', 'CA');
        if (!$caContent) return;

        // 🔹 Fetch TLS auth key
        $taContent = $this->fetchRemoteFile($sshKey, $sshUser, $ip, '/etc/openvpn/ta.key', 'TLS');
        if (!$taContent) return;

        // 🔹 Fetch client cert
        $certContent = $this->fetchRemoteFile($sshKey, $sshUser, $ip, "/etc/openvpn/easy-rsa/pki/issued/{$this->client->username}.crt", 'Client Cert');
        if (!$certContent) return;

        // 🔹 Fetch client key
        $keyContent = $this->fetchRemoteFile($sshKey, $sshUser, $ip, "/etc/openvpn/easy-rsa/pki/private/{$this->client->username}.key", 'Client Key');
        if (!$keyContent) return;

        // 🔹 Load template
        $templatePath = 'ovpn_templates/client.ovpn';
        if (!Storage::exists($templatePath)) {
            Log::error("❌ Missing OVPN template at {$templatePath}");
            return;
        }
        $template = Storage::get($templatePath);

        // 🔹 Replace placeholders
        $config = str_replace(
            ['{{SERVER_IP}}', '{{CA_CERT}}', '{{CLIENT_CERT}}', '{{CLIENT_KEY}}', '{{TLS_AUTH}}'],
            [$ip, $caContent, $certContent, $keyContent, $taContent],
            $template
        );

        // 🔹 Save config file
        $fileName = "ovpn_configs/{$server->name}.ovpn";
        Storage::put($fileName, $config);

        Log::info("✅ OVPN file generated at storage/app/{$fileName}");
    }

    private function fetchRemoteFile(string $sshKey, string $sshUser, string $ip, string $remotePath, string $label): ?string
    {
        $output = [];
        $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat {$remotePath}'";
        exec($cmd, $output, $status);
        $content = implode("\n", $output);

        Log::info("🔧 {$label} fetch status: {$status}");
        Log::info("{$label} Content (first 100 chars): " . substr($content, 0, 100));

        if ($status !== 0 || empty($content)) {
            Log::error("❌ Failed to retrieve {$label} from {$ip} (status {$status})");
            return null;
        }

        return $content;
    }
}
