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
        exec("ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/ca.crt'", $caOutput, $caStatus);
        if ($caStatus !== 0 || empty($caOutput)) {
            Log::error("❌ Failed to retrieve CA cert from $ip");
            return;
        }
        $caBlock = "<ca>\n" . implode("\n", $caOutput) . "\n</ca>";

        // 🔹 Fetch TLS auth key
        $taOutput = [];
        exec("ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/ta.key'", $taOutput, $taStatus);
        if ($taStatus !== 0 || empty($taOutput)) {
            Log::error("❌ Failed to retrieve TLS auth key from $ip");
            return;
        }
        $tlsBlock = "<tls-auth>\n" . implode("\n", $taOutput) . "\n</tls-auth>\nkey-direction 1";

        // 🔹 Fetch client certificate
        $certOutput = [];
        exec("ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/easy-rsa/pki/issued/{$this->client->username}.crt'", $certOutput, $certStatus);
        if ($certStatus !== 0 || empty($certOutput)) {
            Log::error("❌ Failed to retrieve client cert for {$this->client->username}");
            return;
        }
        $certBlock = "<cert>\n" . implode("\n", $certOutput) . "\n</cert>";

        // 🔹 Fetch client private key
        $keyOutput = [];
        exec("ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/easy-rsa/pki/private/{$this->client->username}.key'", $keyOutput, $keyStatus);
        if ($keyStatus !== 0 || empty($keyOutput)) {
            Log::error("❌ Failed to retrieve client key for {$this->client->username}");
            return;
        }
        $keyBlock = "<key>\n" . implode("\n", $keyOutput) . "\n</key>";

        // 🔹 Load OVPN template
        $templatePath = 'ovpn_templates/client.ovpn';
        if (!Storage::exists($templatePath)) {
            Log::error("❌ Missing OVPN template at {$templatePath}");
            return;
        }
        $template = Storage::get($templatePath);

        // 🔹 Replace {{SERVER_IP}}
        $config = str_replace('{{SERVER_IP}}', $ip, $template);

        // 🔹 Append embedded blocks
        $finalConfig = $config . "\n\n" . $certBlock . "\n\n" . $keyBlock . "\n\n" . $caBlock . "\n\n" . $tlsBlock;

        // 🔹 Save file
        $fileName = "ovpn_configs/{$server->name}.ovpn";
        Storage::put($fileName, $finalConfig);

        Log::info("✅ OVPN file generated at storage/app/{$fileName}");
    }
}