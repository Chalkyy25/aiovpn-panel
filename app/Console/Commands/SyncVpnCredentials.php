<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncVpnCredentials extends Command
{
    protected $signature = 'vpn:sync';
    protected $description = 'Sync VPN credentials to the VPN server';

    public function handle(): void
    {
        $this->info("🔐 Generating psw-file for active users...");

        $users = DB::table('vpn_users')
            ->where('is_active', true)
            ->select('username', 'password')
            ->get();

        if ($users->isEmpty()) {
            $this->warn("⚠️ No active users found. Skipping sync.");
            return;
        }

        $lines = $users->map(fn ($u) => "$u->username $u->password")->toArray();
        $content = implode("\n", $lines);

        $localPath = storage_path('vpn/psw-file');

        if (!is_dir(dirname($localPath))) {
            mkdir(dirname($localPath), 0755, true);
        }

        file_put_contents($localPath, $content);

        $this->info("✅ psw-file generated with " . count($lines) . " users.");

        $remotePath = '/etc/openvpn/psw-file';
        $serverIp = '94.237.52.172';
        $sshUser = 'root';
        $sshKey  = '/root/.ssh/github_deploy';

        $command = "scp -i $sshKey -o StrictHostKeyChecking=no $localPath $sshUser@$serverIp:$remotePath";

        exec($command, $output, $status);

        if ($status === 0) {
            $this->info("🚀 VPN credentials synced to $serverIp");
        } else {
            $this->error("❌ Failed to sync VPN credentials via SCP.");
        }
    }

}
