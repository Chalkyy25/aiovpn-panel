<?php

namespace App\Jobs;

use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class SyncOpenVPNCredentials implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function handle(): void
    {
$client = $this->client->load('vpnServer');
$server = $client->vpnServer;
        $ip = $server->ip;
        $sshUser = 'root';
        $sshKey = storage_path('ssh/id_rsa');

        $username = $this->client->username;
        $password = $this->client->password;

        $line = "$username $password\n";
        $remoteFile = "/etc/openvpn/psw-file";

        // Prepare echo command to safely append credentials
        $escapedLine = escapeshellarg($line);

        $cmd = <<<EOD
ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip "echo $escapedLine >> $remoteFile && sort -u $remoteFile -o $remoteFile"
EOD;

        exec($cmd, $output, $status);

        if ($status !== 0) {
            \Log::error("Failed to sync credentials for client {$username} to VPN server {$ip}");
        }
    }
}
