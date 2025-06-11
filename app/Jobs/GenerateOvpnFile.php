<?php

namespace App\Jobs;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateOvpnFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

public function handle(): void
{
    $server = $this->client->vpnServer;
    if (!$server) {
        \Log::error("No VPN server assigned for client {$this->client->username}");
        return;
    }

    $sshUser = 'root';
    $sshKey = storage_path('ssh/id_rsa');
    $ip = $server->ip;

    // 1. Grab the CA cert from the VPN server
    $caOutput = [];
    $cmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip 'cat /etc/openvpn/ca.crt'";
    exec($cmd, $caOutput, $status);

    if ($status !== 0 || empty($caOutput)) {
        \Log::error("Failed to retrieve CA cert from $ip");
        return;
    }

    $caBlock = "<ca>\n" . implode("\n", $caOutput) . "\n</ca>";

    // 2. Load the OVPN template and inject values

$template = Storage::get('ovpn_templates/client.ovpn');

$config = str_replace(
    ['{{SERVER_IP}}', '{{USERNAME}}', '{{PASSWORD}}'],
    [$server->ip, $this->client->username, $this->client->password],
    $template
);

Storage::put("ovpn_configs/{$this->client->username}.ovpn", $config);

     [
        $server->ip,
        $this->client->username,
        $this->client->password,
    ], $template);

    // 3. Append CA cert
    $config .= "\n\n" . $caBlock;

    // 4. Save the final config
    Storage::put("ovpn_configs/{$this->client->username}.ovpn", $config);
}
}
