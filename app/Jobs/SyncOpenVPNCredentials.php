<?php

namespace App\Jobs;

use App\Models\VpnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class SyncOpenVPNCredentials implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 5;

    /** Backoff in seconds (Laravel 10+ supports array backoff) */
    public array $backoff = [10, 30, 60, 120, 240];

    /** Only store the id to avoid serializing a model snapshot */
    public function __construct(public int $vpnServerId) {}

    public function handle(): void
    {
        $server = VpnServer::query()->find($this->vpnServerId);
        if (!$server) {
            // Server deleted? Job becomes a no-op.
            Log::warning("[OpenVPN] Sync skipped: server id={$this->vpnServerId} not found.");
            return;
        }

        $ip      = (string) $server->ip_address;
        $name    = (string) $server->name;
        $sshUser = 'root';

        // Your global default key (keep this unless you store per-server key paths)
        $sshKey = storage_path('app/ssh_keys/id_rsa');
        if (!is_readable($sshKey)) {
            throw new RuntimeException("[OpenVPN] SSH key not readable at: {$sshKey}");
        }

        $remoteDir  = '/etc/openvpn/auth';
        $remoteFile = "{$remoteDir}/psw-file";
        $tmpFile    = storage_path("app/openvpn/psw-{$server->id}.txt");

        Log::info("[OpenVPN] Sync start", [
            'server_id' => $server->id,
            'name'      => $name,
            'ip'        => $ip,
        ]);

        // Pull only what we need (avoid loading huge models)
        $users = $server->vpnUsers()
            ->where('is_active', true)
            ->get(['username', 'plain_password']);

        if ($users->isEmpty()) {
            Log::warning("[OpenVPN] Sync skipped: no active users", [
                'server_id' => $server->id,
                'name'      => $name,
            ]);
            return;
        }

        // Build credentials file (username password per line)
        $content = $users
            ->map(fn ($u) => trim((string) $u->username) . ' ' . trim((string) $u->plain_password))
            ->filter(fn ($line) => $line !== '' && strpos($line, ' ') !== false)
            ->implode("\n") . "\n";

        // Ensure local temp dir exists
        @mkdir(dirname($tmpFile), 0700, true);

        file_put_contents($tmpFile, $content);

        try {
            // Ensure auth directory and permissions
            $this->ssh($ip, $sshUser, $sshKey, "mkdir -p {$remoteDir} && chmod 700 {$remoteDir}", "Create auth dir");

            // Upload file atomically:
            // 1) upload to temp
            // 2) chmod
            // 3) mv over the real file
            $remoteTmp = "{$remoteFile}.tmp";
            $this->scp($ip, $sshUser, $sshKey, $tmpFile, $remoteTmp, "Upload psw-file tmp");
            $this->ssh($ip, $sshUser, $sshKey, "chmod 600 {$remoteTmp} && mv -f {$remoteTmp} {$remoteFile}", "Install psw-file atomically");

            // Restart OpenVPN services
            // Keep both lines: UDP unit + optional TCP unit
            $this->ssh($ip, $sshUser, $sshKey, "systemctl restart openvpn-server@server", "Restart OpenVPN UDP");
            $this->ssh($ip, $sshUser, $sshKey, "systemctl is-enabled openvpn-server@server-tcp >/dev/null 2>&1 && systemctl restart openvpn-server@server-tcp || true", "Restart OpenVPN TCP (if enabled)");

            Log::info("[OpenVPN] Sync complete", [
                'server_id' => $server->id,
                'name'      => $name,
                'ip'        => $ip,
                'users'     => $users->count(),
            ]);
        } finally {
            // Always cleanup local temp
            @unlink($tmpFile);
        }
    }

    /**
     * SSH runner using Symfony Process (safer than raw exec).
     */
    private function ssh(string $ip, string $user, string $keyPath, string $remoteCmd, string $label): void
    {
        // Use bash -lc to keep quoting predictable
        $process = new Process([
            'ssh',
            '-i', $keyPath,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=15',
            '-o', 'BatchMode=yes',
            "{$user}@{$ip}",
            'bash', '-lc', $remoteCmd,
        ]);

        $process->setTimeout(45);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error("[OpenVPN] SSH failed: {$label}", [
                'ip'        => $ip,
                'exit_code' => $process->getExitCode(),
                'stdout'    => $process->getOutput(),
                'stderr'    => $process->getErrorOutput(),
                'cmd'       => $remoteCmd,
            ]);
            throw new RuntimeException("[OpenVPN] SSH failed: {$label}");
        }

        Log::info("[OpenVPN] SSH ok: {$label}", [
            'ip'     => $ip,
            'stdout' => trim($process->getOutput()),
        ]);
    }

    /**
     * SCP runner using Symfony Process (safer than raw exec).
     */
    private function scp(string $ip, string $user, string $keyPath, string $localPath, string $remotePath, string $label): void
    {
        if (!is_readable($localPath)) {
            throw new RuntimeException("[OpenVPN] Local file missing/unreadable: {$localPath}");
        }

        $process = new Process([
            'scp',
            '-i', $keyPath,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'ConnectTimeout=15',
            '-o', 'BatchMode=yes',
            $localPath,
            "{$user}@{$ip}:{$remotePath}",
        ]);

        $process->setTimeout(45);
        $process->run();

        if (!$process->isSuccessful()) {
            Log::error("[OpenVPN] SCP failed: {$label}", [
                'ip'        => $ip,
                'exit_code' => $process->getExitCode(),
                'stdout'    => $process->getOutput(),
                'stderr'    => $process->getErrorOutput(),
            ]);
            throw new RuntimeException("[OpenVPN] SCP failed: {$label}");
        }

        Log::info("[OpenVPN] SCP ok: {$label}", [
            'ip' => $ip,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error("[OpenVPN] Job failed", [
            'server_id' => $this->vpnServerId,
            'error'     => $e->getMessage(),
        ]);
    }
}