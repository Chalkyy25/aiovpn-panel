<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SyncOpenVPNCredentials implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VpnServer $vpnServer;

    public function __construct(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
    }

    public function handle(): void
    {
        $vpnServer = $this->vpnServer->fresh();
        if (!$vpnServer) {
            Log::error("❌ VPN server not found.");
            return;
        }

        $users = $vpnServer->vpnUsers()->get();
        if ($users->isEmpty()) {
            Log::info("No VPN users found for server #{$vpnServer->id}. Skipping sync.");
            return;
        }
        $lines = $users->map(fn ($u) => "{$u->username} {$u->password}")->toArray();



        $tmpFile = storage_path("app/psw-file-{$vpnServer->id}.txt");
        file_put_contents($tmpFile, implode("\n", $lines) . "\n");

        $ip = $vpnServer->ip_address;
        $sshUser = 'root';
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        $remoteDir = '/etc/openvpn/auth';
        $remoteFile = "$remoteDir/psw-file";

        // Ensure auth dir exists
        $mkdirCmd = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'mkdir -p {$remoteDir} && chmod 700 {$remoteDir}'";
        exec($mkdirCmd);

        // Copy file
        $scpCmd = "scp -i {$sshKey} -o StrictHostKeyChecking=no {$tmpFile} {$sshUser}@{$ip}:{$remoteFile}";
        exec($scpCmd, $output, $status);

        if ($status !== 0) {
            Log::error("❌ Failed to sync credentials to {$ip}: " . implode("\n", $output));
        } else {
            Log::info("✅ Synced " . count($lines) . " VPN credentials to {$ip}");
        }

        // Optional: restart OpenVPN to pick up the new file
        $restartCmd = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'systemctl restart openvpn@server'";
        exec($restartCmd);

        @unlink($tmpFile);
    }
}
