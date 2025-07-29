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
        try {
            $server = VpnServer::findOrFail($this->vpnServer->id);

            Log::info("🔍 [OpenVPN] Retrieved server: " . json_encode([
                'id' => $server->id,
                'name' => $server->name,
                'ip' => $server->ip_address
            ]));

            $ip = $server->ip_address;
            $sshKey = storage_path('app/ssh_keys/id_rsa');
            $sshUser = 'root';
            $remoteDir = '/etc/openvpn/auth';
            $remoteFile = "$remoteDir/psw-file";

            Log::info("🔄 [OpenVPN] Syncing credentials to $server->name ($ip)");

            $users = $server->vpnUsers()->where('is_active', true)->get();
            Log::info("👥 [OpenVPN] Found {$users->count()} active user(s) for $server->name");

            if ($users->isEmpty()) {
                Log::warning("⚠️ [OpenVPN] No users found for $server->name. Skipping.");
                return;
            }

            // 🔐 Create credentials content
            $lines = $users->map(fn($u) => "$u->username $u->plain_password")->toArray();
            $content = implode("\n", $lines) . "\n";

            $tmpFile = storage_path("app/psw-$server->id.txt");
            file_put_contents($tmpFile, $content);
            Log::info("📄 [OpenVPN] Temporary psw-file created: $tmpFile");

            // 🔧 Ensure /auth dir exists
            $this->runSsh("mkdir -p $remoteDir && chmod 700 $remoteDir", $ip, $sshKey, $sshUser, "Create auth dir");

            // 🚀 Upload psw-file
            $this->runScp($tmpFile, $remoteFile, $ip, $sshKey, $sshUser);

            // 🔒 Set remote file permissions
            $this->runSsh("chmod 600 $remoteFile", $ip, $sshKey, $sshUser, "Set file permissions");

            // 🔁 Restart OpenVPN
            $this->runSsh("systemctl restart openvpn@server", $ip, $sshKey, $sshUser, "Restart OpenVPN");

            // 🧹 Cleanup
            @unlink($tmpFile);
            Log::info("🧼 [OpenVPN] Temp file deleted. Sync complete for $server->name");

        } catch (\Exception $e) {
            Log::error("❌ [OpenVPN] Error in sync job: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    private function runSsh(string $command, string $ip, string $sshKey, string $sshUser, string $label): void
    {
        $fullCmd = "ssh -i $sshKey -o StrictHostKeyChecking=no $sshUser@$ip '$command'";
        exec($fullCmd, $output, $status);

        if ($status !== 0) {
            Log::error("❌ [OpenVPN] $label failed on $ip: " . implode("\n", $output));
            throw new \RuntimeException("SSH command failed: $label");
        } else {
            Log::info("✅ [OpenVPN] $label success on $ip");
        }
    }

    private function runScp(string $localPath, string $remotePath, string $ip, string $sshKey, string $sshUser): void
    {
        $cmd = "scp -i $sshKey -o StrictHostKeyChecking=no $localPath $sshUser@$ip:$remotePath";
        exec($cmd, $output, $status);

        if ($status !== 0) {
            Log::error("❌ [OpenVPN] SCP failed to $ip: " . implode("\n", $output));
            throw new \RuntimeException("SCP command failed");
        } else {
            Log::info("📦 [OpenVPN] psw-file uploaded to $ip");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("💥 [OpenVPN] Job failed for server {$this->vpnServer->name}: " . $exception->getMessage());
    }
}
