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
        Log::info("🔧 CA fetch status: {$caStatus}");
        Log::info("CA Content: " . substr($caContent, 0, 100));

        if ($caStatus !== 0 || empty($caContent)) {
            Log::error("❌ Failed to retrieve CA cert from $ip (status $caStatus)");
            return;
        }

        // 🔹 Fetch TLS auth key
        $taOutput = [];
        $taCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/ta.key'";
        exec($taCmd, $taOutput, $taStatus);
        $taContent = implode("\n", $taOutput);
        Log::info("🔧 TLS fetch status: {$taStatus}");
        Log::info("TLS Auth Content: " . substr($taContent, 0, 100));

        if ($taStatus !== 0 || empty($taContent)) {
            Log::error("❌ Failed to retrieve TLS auth key from $ip (status $taStatus)");
            return;
        }

        // 🔹 Fetch client cert
        $certOutput = [];
        $certCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/easy-rsa/pki/issued/{$this->client->username}.crt'";
        exec($certCmd, $certOutput, $certStatus);
        $certContent = implode("\n", $certOutput);
        Log::info("🔧 Client cert fetch status: {$certStatus}");
        Log::info("Client Cert Content: " . substr($certContent, 0, 100));

        if ($certStatus !== 0 || empty($certContent)) {
            Log::error("❌ Failed to retrieve client cert for {$this->client->username} from $ip (status $certStatus)");
            return;
        }

        // 🔹 Fetch client key
        $keyOutput = [];
        $keyCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/easy-rsa/pki/private/{$this->client->username}.key'";
        exec($keyCmd, $keyOutput, $keyStatus);
        $keyContent = implode("\n", $keyOutput);
        Log::info("🔧 Client key fetch status: {$keyStatus}");
        Log::info("Client Key Content: " . substr($keyContent, 0, 100));

        if ($keyStatus !== 0 || empty($keyContent)) {
            Log::error("❌ Failed to retrieve client key for {$this->client->username} from $ip (status $keyStatus)");
            return;
        }

        // 🔹 Load template
        $templatePath = 'ovpn_templates/client.ovpn';
        if (!Storage::exists($templatePath)) {
            Log::error("❌ Missing OVPN template at {$templatePath}");
            return;
        }
        $template = Storage::get($templatePath);

        // 🔹 Replace all placeholders
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