<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class SyncVpnCredentials extends Command
{
    protected $signature = 'vpn:sync';
    protected $description = 'Sync VPN credentials to the VPN server';

public function handle()
{
    $this->info("Syncing VPN credentials...");

    $localPath = storage_path('vpn/psw-file');
    $remotePath = '/etc/openvpn/psw-file';
    $serverIp = '94.237.52.172';
    $sshUser = 'root';

	$command = "scp -i /root/.ssh/github_deploy -o StrictHostKeyChecking=no {$localPath} {$sshUser}@{$serverIp}:{$remotePath}";

    exec($command, $output, $status);

    if ($status === 0) {
        $this->info("VPN credentials synced successfully.");
    }
 else {
        $this->error("Failed to sync VPN credentials.");
    }
}
}
