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

        $blocks = [
            'CA' => '/etc/openvpn/ca.crt',
            'TLS' => '/etc/openvpn/ta.key',
            'Client Cert' => "/etc/openvpn/easy-rsa/pki/issued/{$this->client->username}.crt",
            'Client Key' => "/etc/openvpn/easy-rsa/pki/private/{$this->client->username}.key",
        ];

        $embedded = [];

        foreach ($blocks as $label => $path) {
            $output = [];
            $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat {$path}'";
            exec($cmd, $output, $status);
            $content = implode("\n", $output);

            Log::info("🔧 {$label} fetch status: {$status}");
            Log::info("{$label} Content (first 100 chars): " . substr($content, 0, 100));

            if ($status !== 0 || empty($content)) {
                Log::error("❌ Failed to retrieve {$label} from {$ip} (status {$status})");
                return;
            }

            $tag = match ($label) {
                'CA' => 'ca',
                'TLS' => 'tls-auth',
                'Client Cert' => 'cert',
                'Client Key' => 'key',
            };

            $block = "<{$tag}>\n{$content}\n</{$tag}>";

            if ($label === 'TLS') {
                $block .= "\nkey-direction 1";
            }

            $embedded[$label] = $block;
        }

        // 🔹 Load .ovpn template
        $templatePath = 'ovpn_templates/client.ovpn';
        if (!Storage::exists($templatePath)) {
            Log::error("❌ Missing OVPN template at {$templatePath}");
            return;
        }

        $template = Storage::get($templatePath);

        // 🔹 Replace placeholders and append embedded blocks
        $config = str_replace(['{{SERVER_IP}}'], [$ip], $template);
        $config .= "\n\n" . implode("\n\n", $embedded);

        // 🔹 Save .ovpn config
        $fileName = "ovpn_configs/{$server->name}.ovpn";
        Storage::put($fileName, $config);

        Log::info("✅ OVPN file generated at storage/app/{$fileName}");
    }
}