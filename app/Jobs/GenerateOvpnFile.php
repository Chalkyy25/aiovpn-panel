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
        $caOutput = [];
        $caCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/ca.crt'";
        exec($caCmd, $caOutput, $caStatus);
        Log::info("🔧 CA fetch status: {$caStatus}");
        if ($caStatus !== 0 || empty($caOutput)) {
            Log::error("❌ Failed to retrieve CA cert from $ip (status $caStatus)");
            return;
        }
        $caBlock = "<ca>\n" . implode("\n", $caOutput) . "\n</ca>";

        // 🔹 Fetch TLS auth key (ta.key)
        $taOutput = [];
        $taCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/ta.key'";
        exec($taCmd, $taOutput, $taStatus);
        Log::info("🔧 TLS fetch status: {$taStatus}");
        if ($taStatus !== 0 || empty($taOutput)) {
            Log::error("❌ Failed to retrieve TLS auth key from $ip (status $taStatus)");
            return;
        }
        $tlsBlock = "<tls-auth>\n" . implode("\n", $taOutput) . "\n</tls-auth>\nkey-direction 1";

        // 🔹 Load OVPN template and inject values
        $templatePath = 'ovpn_templates/client.ovpn';
        if (!Storage::exists($templatePath)) {
            Log::error("❌ Missing OVPN template at {$templatePath}");
            return;
        }
        $template = Storage::get($templatePath);

        // 🔹 Insert user/pass block inline
        $userpassBlock = "<auth-user-pass>\n{$this->client->username}\n{$this->client->password}\n</auth-user-pass>";

        $config = str_replace(
            ['{{SERVER_IP}}'],
            [$ip],
            $template
        );

        // 🔹 Append all blocks
        $config .= "\n\n" . $userpassBlock . "\n\n" . $caBlock . "\n\n" . $tlsBlock;

        // 🔹 Save final config using server name
        $fileName = "ovpn_configs/{$server->name}.ovpn";
        Storage::put($fileName, $config);

        Log::info("✅ OVPN file generated at storage/app/{$fileName}");
    }
}
