<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncVpnCredentials implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("🔐 Starting VPN credentials sync job...");

        $users = DB::table('vpn_users')
            ->where('is_active', true)
            ->select('username', 'password')
            ->get();

        if ($users->isEmpty()) {
            Log::warning("⚠️ No active users found. Skipping sync.");
            return;
        }

        $lines = $users->map(fn ($u) => "$u->username $u->password")->toArray();
        $content = implode("\n", $lines);

        $localPath = storage_path('vpn/psw-file');

        if (!is_dir(dirname($localPath))) {
            mkdir(dirname($localPath), 0755, true);
        }

        file_put_contents($localPath, $content);

        Log::info("✅ psw-file generated with " . count($lines) . " users.");

        $remotePath = '/etc/openvpn/psw-file';
        $serverIp = '94.237.52.172';
        $sshUser = 'root';
        $sshKey  = '/root/.ssh/github_deploy';

        $command = "scp -i $sshKey -o StrictHostKeyChecking=no $localPath $sshUser@$serverIp:$remotePath";

        exec($command, $output, $status);

        if ($status === 0) {
            Log::info("🚀 VPN credentials synced to $serverIp");
        } else {
            Log::error("❌ Failed to sync VPN credentials via SCP.");
            throw new \Exception("Failed to sync VPN credentials via SCP. Exit code: $status");
        }
    }
}
