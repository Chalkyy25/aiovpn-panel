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
    /**
     * Execute the job to generate an OVPN file for the client.
     */
    // This job will be dispatched when a new VPN user is created or updated.
    // It will generate the OVPN file using the server's CA cert and the user's credentials
    // and save it in the storage/ovpn_configs directory.   

    public function handle(): void
    {
        $server = $this->client->vpnServer;

        if (!$server) {
            Log::error("❌ No VPN server assigned for client {$this->client->username}");
            return;
        }

        $sshUser = 'root';
        $sshKey = storage_path('ssh/id_rsa');
        $ip = $server->ip_address; // ✅ fixed from $server->ip

        Log::info("🔑 Generating .ovpn for client {$this->client->username} on server {$server->name} ({$ip})");

        // 1. Grab the CA cert from the VPN server
        $caOutput = [];
        $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/ca.crt'";
        exec($cmd, $caOutput, $status);

        if ($status !== 0 || empty($caOutput)) {
            Log::error("❌ Failed to retrieve CA cert from $ip (status $status)");
            return;
        }

        $caBlock = "<ca>\n" . implode("\n", $caOutput) . "\n</ca>";

        // 2. Load the OVPN template and inject values
        $templatePath = 'ovpn_templates/client.ovpn';
        if (!Storage::exists($templatePath)) {
            Log::error("❌ Missing OVPN template at {$templatePath}");
            return;
        }

        $template = Storage::get($templatePath);

        $config = str_replace(
            ['{{SERVER_IP}}', '{{USERNAME}}', '{{PASSWORD}}'],
            [$ip, $this->client->username, $this->client->password],
            $template
        );

        // 3. Append CA cert
        $config .= "\n\n" . $caBlock;

        // 4. Save the final config using server name
        $fileName = "ovpn_configs/{$server->name}.ovpn";
        Storage::put($fileName, $config);

        Log::info("✅ OVPN file generated at storage/app/{$fileName}");
    }
}