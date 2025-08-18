<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUser;
use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\SyncOpenVPNCredentials;

class DeployVpnServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Max runtime for this job (seconds). Adjust to taste. */
    public $timeout = 900;

    /** Retry attempts if the job throws. */
    public $tries = 2;

    public VpnServer $vpnServer;

    public function __construct(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
    }

    public function handle(): void
    {
        Log::info("🚀 DeployVpnServer: starting for #{$this->vpnServer->id}");

        if ($this->vpnServer->is_deploying) {
            Log::warning("⚠️ DeployVpnServer: already deploying #{$this->vpnServer->id}");
            return;
        }

        // Panel config (Option 4)
        $panelUrl   = rtrim((string) config('services.panel.base'), '/');
        $panelToken = (string) config('services.panel.token');

        if (blank($panelUrl) || blank($panelToken)) {
            $this->failWith('❌ PANEL config missing: set PANEL_BASE_URL and PANEL_TOKEN in .env');
            return;
        }

        // Basic server facts
        $ip      = (string) $this->vpnServer->ip_address;
        $port    = (int) ($this->vpnServer->ssh_port ?? 22);
        $user    = (string) ($this->vpnServer->ssh_user ?? 'root');
        $sshType = (string) ($this->vpnServer->ssh_type ?? 'key'); // 'key' | 'password'

        if (blank($ip) || blank($user)) {
            $this->failWith('❌ Server IP or SSH user is missing');
            return;
        }

        // Optional VPN/mgmt values (use model if present, else sane defaults)
        $vpnPort   = (int) ($this->vpnServer->port ?? 1194);
        $vpnProto  = (string) ($this->vpnServer->protocol ?? 'udp');
        $mgmtHost  = '127.0.0.1';
        $mgmtPort  = 7505;
        $wgPort    = 51820;

        // Prepare script + environment
        $scriptPath = base_path('resources/scripts/deploy-openvpn.sh');
        if (!is_file($scriptPath)) {
            $this->failWith("❌ Missing deployment script at {$scriptPath}");
            return;
        }
        $script = file_get_contents($scriptPath);

        // Reuse an active user if one exists, otherwise seed admin creds
        $vpnUser = 'admin';
        $vpnPass = substr(bin2hex(random_bytes(16)), 0, 16);

        if ($existing = $this->vpnServer->vpnUsers()->where('is_active', true)->first()) {
            $vpnUser = (string) $existing->username;
            $vpnPass = (string) ($existing->plain_password ?: $vpnPass);
            Log::info("🔑 Reusing existing active user: {$vpnUser}");
        } else {
            Log::info("🔑 Seeding VPN user: {$vpnUser}");
        }

        // Update DB status before starting
        $this->vpnServer->update([
            'is_deploying'      => true,
            'deployment_status' => 'running',
            'deployment_log'    => "🚀 Starting deployment on {$ip}…\n",
        ]);

        try {
            // Build SSH command
            $sshOpts = implode(' ', [
                '-o StrictHostKeyChecking=no',
                '-o UserKnownHostsFile=/dev/null',
                '-o ConnectTimeout=30',
                '-o ServerAliveInterval=15',
                '-o ServerAliveCountMax=4',
                '-p ' . (int) $port,
            ]);

            $sshCmdBase = '';
            if ($sshType === 'password') {
                // Ensure sshpass exists on PANEL host
                $have = trim((string) shell_exec('command -v sshpass || true'));
                if ($have === '') {
                    $this->failWith('❌ sshpass is required on the panel host for password auth. Install it or switch to key auth.');
                    return;
                }
                $sshPass = (string) $this->vpnServer->ssh_password;
                if (blank($sshPass)) {
                    $this->failWith('❌ SSH password not set for password auth.');
                    return;
                }
                $sshCmdBase = 'sshpass -p ' . escapeshellarg($sshPass)
                    . ' ssh ' . $sshOpts . ' ' . escapeshellarg("{$user}@{$ip}");
            } else {
                // Key auth
                $keyValue = (string) ($this->vpnServer->ssh_key ?: 'id_rsa');
                $keyPath  = str_starts_with($keyValue, '/') || str_contains($keyValue, ':\\')
                    ? $keyValue
                    : storage_path('app/ssh_keys/' . $keyValue);

                if (!is_file($keyPath)) {
                    $this->failWith("❌ SSH key not found at {$keyPath}");
                    return;
                }

                // ssh requires 0600 on private keys
                @chmod($keyPath, 0600);

                $sshCmdBase = 'ssh -i ' . escapeshellarg($keyPath)
                    . ' ' . $sshOpts . ' ' . escapeshellarg("{$user}@{$ip}");
            }

            // Quick connection test
            $testOutput = [];
            $testStatus = 0;
            exec($sshCmdBase . ' ' . escapeshellarg('echo CONNECTION_OK'), $testOutput, $testStatus);
            if ($testStatus !== 0 || !in_array('CONNECTION_OK', $testOutput, true)) {
                $this->failWith("❌ SSH connection failed to {$ip}");
                return;
            }
            Log::info("✅ SSH test OK for {$ip}");

            // Env for remote script
            $env = [
                'PANEL_URL'  => $panelUrl,
                'PANEL_TOKEN'=> $panelToken,
                'SERVER_ID'  => (string) $this->vpnServer->id,
                'MGMT_HOST'  => $mgmtHost,
                'MGMT_PORT'  => (string) $mgmtPort,
                'VPN_PORT'   => (string) $vpnPort,
                'VPN_PROTO'  => $vpnProto,
                'VPN_USER'   => $vpnUser,
                'VPN_PASS'   => $vpnPass,
                'WG_PORT'    => (string) $wgPort,
            ];

            // Build the remote command
            $envExport = implode(' ', array_map(
                fn ($k, $v) => $k . '=' . escapeshellarg($v),
                array_keys($env),
                array_values($env)
            ));

            $remote = $envExport . ' ' . $sshCmdBase . " 'bash -se < /dev/stdin ; echo EXIT_CODE:\$?'";
            Log::info('🔧 Remote deploy cmd: ' . $remote);

            // Stream the script to the remote host
            $descriptors = [
                0 => ['pipe', 'r'], // stdin
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];

            $proc  = proc_open($remote, $descriptors, $pipes, base_path());
            if (!is_resource($proc)) {
                $this->failWith('❌ Failed to open SSH process');
                return;
            }

            // Send script
            fwrite($pipes[0], $script);
            fclose($pipes[0]);

            $out = '';
            $err = '';
            $streams = [$pipes[1], $pipes[2]];
            $map = [(int) $pipes[1] => 'OUT', (int) $pipes[2] => 'ERR'];

            while (!empty($streams)) {
                $read = $streams;
                $write = $except = null;

                if (stream_select($read, $write, $except, 10) === false) {
                    break;
                }

                foreach ($read as $r) {
                    $line = fgets($r);
                    if ($line === false) {
                        fclose($r);
                        unset($streams[array_search($r, $streams, true)]);
                        continue;
                    }

                    $clean = rtrim($line, "\r\n");

                    // Append to DB log (and dedupe done on read in your Livewire)
                    $this->vpnServer->appendLog($clean);

                    if ($map[(int) $r] === 'OUT') {
                        $out .= $line;
                    } else {
                        $err .= $line;
                    }
                }
            }

            $exitCode = proc_close($proc);

            // Also parse explicit EXIT_CODE marker if present
            $combined = $this->vpnServer->deployment_log . $out . $err;
            if (preg_match('/EXIT_CODE:(\d+)/', $combined, $m)) {
                $exitCode = (int) $m[1];
            }

            $status = $exitCode === 0 ? 'succeeded' : 'failed';
            $finalLog = $this->stripNoise($combined);
            if ($exitCode === 0) {
                $finalLog .= "\n✅ Deployment succeeded";

                // Auto-assign existing active users (kept from your original)
                $existingUsers = VpnUser::where('is_active', true)->get();
                if ($existingUsers->isNotEmpty()) {
                    $this->vpnServer->vpnUsers()->syncWithoutDetaching($existingUsers->pluck('id')->all());
                    $finalLog .= "\n👥 Auto-assigned {$existingUsers->count()} existing users to server";
                }

                // Kick a credentials sync pass
                SyncOpenVPNCredentials::dispatch($this->vpnServer);
            } else {
                $finalLog .= "\n❌ Deployment failed (exit code: {$exitCode})";
            }

            $this->vpnServer->update([
                'is_deploying'      => false,
                'deployment_status' => $status,
                'deployment_log'    => $finalLog,
                'status'            => $exitCode === 0 ? 'online' : 'offline',
            ]);

            Log::info("✅ DeployVpnServer: done for #{$this->vpnServer->id} (exit={$exitCode})");
        } catch (Throwable $e) {
            $this->failWith('❌ Exception during deployment: ' . $e->getMessage(), $e);
        }
    }

    public function failed(Throwable $e): void
    {
        $this->failWith('❌ Job failed with exception: ' . $e->getMessage(), $e);
    }

    private function failWith(string $message, Throwable $e = null): void
    {
        Log::error($message);
        if ($e) {
            Log::error($e);
        }

        $this->vpnServer->update([
            'is_deploying'      => false,
            'deployment_status' => 'failed',
            'deployment_log'    => ($this->vpnServer->deployment_log ?? '') . "\n" . $message,
            'status'            => 'offline',
        ]);
    }

    /**
     * Remove noisy lines from log output so your UI stays clean.
     */
    private function stripNoise(string $log): string
    {
        $lines = explode("\n", $log);
        $keep  = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if (preg_match('/^(Generating DH parameters|DH parameters appear to be ok|DEPRECATED OPTION)/i', $t)) {
                continue;
            }
            $keep[] = $t;
        }
        return implode("\n", $keep);
    }
}