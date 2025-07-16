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

    /**
     * Create a new job instance.
     */
    public function __construct(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $server = $this->vpnServer->fresh();

        if (!$server) {
            Log::error("❌ [OpenVPN] Server not found. Aborting sync job.");
            return;
        }

        Log::info("🔄 [OpenVPN] Starting credentials sync for {$server->name} ({$server->ip_address})");

        // Fetch VPN users
        $users = $server->vpnUsers()->get();

        if ($users->isEmpty()) {
            Log::warning("⚠️ [OpenVPN] No VPN users found for {$server->name}. Skipping sync.");
            return;
        }

        Log::info("👥 [OpenVPN] Found {$users->count()} users for {$server->name}");

        // Build credentials lines
	$lines = $users->map(fn ($u) => "{$u->username} {$u->plain_password}")->toArray();
	Log::info("🔑 [OpenVPN] Credentials lines:", $lines);
        $content = implode("\n", $lines) . "\n";

        // Write to temp file
        $tmpFile = storage_path("app/psw-file-{$server->id}.txt");
        file_put_contents($tmpFile, $content);
        Log::info("📝 [OpenVPN] Credentials file created at {$tmpFile}");

        // Prepare SSH details
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        $sshUser = 'root';
        $ip = $server->ip_address;
        $remoteDir = '/etc/openvpn/auth';
        $remoteFile = "{$remoteDir}/psw-file";

        // Ensure remote auth directory exists
        $mkdirCmd = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'mkdir -p {$remoteDir} && chmod 700 {$remoteDir}'";
        exec($mkdirCmd, $mkdirOutput, $mkdirStatus);

        if ($mkdirStatus !== 0) {
            Log::error("❌ [OpenVPN] Failed to create auth directory on {$ip}: " . implode("\n", $mkdirOutput));
            @unlink($tmpFile);
            return;
        }

        // SCP upload credentials file
        $scpCmd = "scp -i {$sshKey} -o StrictHostKeyChecking=no {$tmpFile} {$sshUser}@{$ip}:{$remoteFile}";
        exec($scpCmd, $scpOutput, $scpStatus);

        if ($scpStatus !== 0) {
            Log::error("❌ [OpenVPN] Failed to upload credentials to {$ip}: " . implode("\n", $scpOutput));
            @unlink($tmpFile);
            return;
        }

        Log::info("✅ [OpenVPN] Credentials synced to {$ip} ({$users->count()} users)");

        // Restart OpenVPN to apply changes (optional)
        $restartCmd = "ssh -i {$sshKey} -o StrictHostKeyChecking=no {$sshUser}@{$ip} 'systemctl restart openvpn@server'";
        exec($restartCmd, $restartOutput, $restartStatus);

        if ($restartStatus !== 0) {
            Log::error("❌ [OpenVPN] Failed to restart OpenVPN on {$ip}: " . implode("\n", $restartOutput));
        } else {
            Log::info("🔁 [OpenVPN] OpenVPN restarted on {$ip} successfully.");
        }

        // Cleanup
        @unlink($tmpFile);
        Log::info("🧹 [OpenVPN] Temporary credentials file deleted for {$server->name}");
    }
}
