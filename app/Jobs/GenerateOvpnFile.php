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
    $caContent = implode("\n", $caOutput);

    // 🔹 Fetch TLS auth key
    $taOutput = [];
    $taCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/ta.key'";
    exec($taCmd, $taOutput, $taStatus);
    $taContent = implode("\n", $taOutput);

    // 🔹 Fetch client cert
    $certOutput = [];
    $certCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/easy-rsa/pki/issued/{$this->client->username}.crt'";
    exec($certCmd, $certOutput, $certStatus);
    $certContent = implode("\n", $certOutput);

    // 🔹 Fetch client key
    $keyOutput = [];
    $keyCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/easy-rsa/pki/private/{$this->client->username}.key'";
    exec($keyCmd, $keyOutput, $keyStatus);
    $keyContent = implode("\n", $keyOutput);

    // 🔹 Load template
    $templatePath = 'ovpn_templates/client.ovpn';
    if (!Storage::exists($templatePath)) {
        Log::error("❌ Missing OVPN template at {$templatePath}");
        return;
    }
    $template = Storage::get($templatePath);

    // 🔹 Replace all placeholders in one go
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

}
