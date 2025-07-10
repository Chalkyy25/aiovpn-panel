<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        Log::info("🔄 [OpenVPN] Starting credentials sync for server {$this->vpnServer->name} ({$this->vpnServer->ip_address})");

        $vpnServer = $this->vpnServer->fresh();
        if (!$vpnServer) {
            Log::error("❌ VPN server not found.");
            return;
        }

        $users = $vpnServer->vpnUsers()->get();
        Log::info("👥 [OpenVPN] Found {$users->count()} users for server {$vpnServer->name}");

        if ($users->isEmpty()) {
            Log::warning("⚠️ [OpenVPN] No VPN users found for {$vpnServer->name}. Skipping sync.");
            return;
        }

        // Build credentials lines
        $lines = $users->map(fn ($u) => "{$u->username} {$u->password}")->toArray();

        // Write to temporary file
        $tmpFile = storage_path("app/psw-file-{$vpnServer->id}.txt");
        file_put_contents($tmpFile, implode("\n", $lines) . "\n");
        Log::info("📝 [OpenVPN] Credentials file created at {$tmpFile}");

        $ip = $vpnServer->ip_address;
        $sshUser = 'root';
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        $remoteDir = '/etc/openvpn/auth';
        $remoteFile = "{$remoteDir}/psw-file";

        // Ensure auth directory exists on server
        $mkdirCmd = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'mkdir -p {$remoteDir} && chmod 700 {$remoteDir}'";
        exec($mkdirCmd, $mkdirOutput, $mkdirStatus);
        if ($mkdirStatus !== 0) {
            Log::error("❌ [OpenVPN] Failed to create auth directory on {$ip}: " . implode("\n", $mkdirOutput));
            @unlink($tmpFile);
            return;
        }

        // SCP upload
        $scpCmd = "scp -i {$sshKey} -o StrictHostKeyChecking=no {$tmpFile} {$sshUser}@{$ip}:{$remoteFile}";
        exec($scpCmd, $scpOutput, $scpStatus);
        if ($scpStatus !== 0) {
            Log::error("❌ [OpenVPN] Failed to upload credentials file to {$ip}: " . implode("\n", $scpOutput));
            @unlink($tmpFile);
            return;
        }

        Log::info("✅ [OpenVPN] Synced credentials to {$ip} with " . count($lines) . " users");

        // Restart OpenVPN to pick up changes (optional)
        $restartCmd = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'systemctl restart openvpn@server'";
        exec($restartCmd, $restartOutput, $restartStatus);
        if ($restartStatus !== 0) {
            Log::error("❌ [OpenVPN] Failed to restart OpenVPN on {$ip}: " . implode("\n", $restartOutput));
        } else {
            Log::info("🔁 [OpenVPN] Restarted OpenVPN on {$ip} successfully.");
        }

        // Clean up temporary file
        @unlink($tmpFile);
        Log::info("🧹 [OpenVPN] Temporary credentials file deleted for server {$vpnServer->name}");
    }
}
