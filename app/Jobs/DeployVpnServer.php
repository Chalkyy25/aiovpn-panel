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

/**
 * Provisions an OpenVPN/WireGuard host over SSH using resources/scripts/deploy-openvpn.sh.
 * Streams remote output into the server's deployment_log and marks status accordingly.
 *
 * Notes:
 * - Uses OpenVPN status-v3 push agent (timer) installed by the deploy script.
 * - Legacy mgmt pusher is disabled by default to avoid duplicates.
 */
class DeployVpnServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Seconds the queue worker allows this job to run */
    public $timeout = 900;

    /** @var int Number of attempts */
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

        // Panel config (env-driven)
        $panelUrl   = rtrim((string) config('services.panel.base'), '/');
        $panelToken = (string) config('services.panel.token');

        if ($panelUrl === '' || $panelToken === '') {
            $this->failWith('❌ PANEL config missing: set PANEL_BASE_URL and PANEL_TOKEN in .env then php artisan config:cache');
            return;
        }

        // Server facts
        $ip      = (string) $this->vpnServer->ip_address;
        $port    = (int) ($this->vpnServer->ssh_port ?: 22);
        $user    = (string) ($this->vpnServer->ssh_user ?: 'root');
        $sshType = (string) ($this->vpnServer->ssh_type ?: 'key'); // 'key' | 'password'

        if ($ip === '' || $user === '') {
            $this->failWith('❌ Server IP or SSH user is missing');
            return;
        }

        // Normalize proto to udp/tcp only
        $modelProto = strtolower((string) ($this->vpnServer->protocol ?: 'udp'));
        $vpnProto   = $modelProto === 'tcp' ? 'tcp' : 'udp';

        $vpnPort  = (int) ($this->vpnServer->port ?: 1194);
        $mgmtHost = '127.0.0.1';
        $mgmtPort = (int) ($this->vpnServer->mgmt_port ?: 7505);
        $wgPort   = 51820;

        // Load deploy script
        $scriptPath = base_path('resources/scripts/deploy-openvpn.sh');
        if (!is_file($scriptPath)) {
            $this->failWith("❌ Missing deployment script at {$scriptPath}");
            return;
        }
        $script = file_get_contents($scriptPath);

        // Seed/Reuse VPN creds
        $vpnUser = 'admin';
        $vpnPass = substr(bin2hex(random_bytes(16)), 0, 16);
        if ($existing = $this->vpnServer->vpnUsers()->where('is_active', true)->first()) {
            $vpnUser = (string) $existing->username;
            $vpnPass = (string) ($existing->plain_password ?: $vpnPass);
            Log::info("🔑 Reusing existing active user: {$vpnUser}");
        } else {
            Log::info("🔑 Seeding VPN user: {$vpnUser}");
        }

        // Mark as running
        $this->vpnServer->update([
            'is_deploying'      => true,
            'deployment_status' => 'running',
            'deployment_log'    => "🚀 Starting deployment on {$ip}…\n",
        ]);

        try {
            // SSH base
            $sshCmdBase = $this->buildSshBase($sshType, $user, $ip, $port);
            if ($sshCmdBase === null) {
                // buildSshBase already called failWith
                return;
            }

            // SSH sanity test
            $testOutput = [];
            $testStatus = 0;
            exec($sshCmdBase . ' ' . escapeshellarg('echo CONNECTION_OK'), $testOutput, $testStatus);
            if ($testStatus !== 0 || !in_array('CONNECTION_OK', $testOutput, true)) {
                $this->failWith("❌ SSH connection failed to {$ip}");
                return;
            }
            Log::info("✅ SSH test OK for {$ip}");

            // Remote env (explicit beats implicit)
            $env = [
                'PANEL_URL'   => $panelUrl,
                'PANEL_TOKEN' => $panelToken,
                'SERVER_ID'   => (string) $this->vpnServer->id,

                // VPN/mgmt tunables
                'MGMT_HOST'   => $mgmtHost,
                'MGMT_PORT'   => (string) $mgmtPort,
                'VPN_PORT'    => (string) $vpnPort,
                'VPN_PROTO'   => $vpnProto,
                'WG_PORT'     => (string) $wgPort,

                // seed auth
                'VPN_USER'    => $vpnUser,
                'VPN_PASS'    => $vpnPass,

                // agent envs
                'STATUS_PATH'           => '/run/openvpn/server.status',
                'STATUS_PUSH_INTERVAL'  => (string) (config('services.vpn.status_push_interval', 5)),
                'PANEL_CALLBACKS'       => '1',  // POST back to panel
                'PUSH_MGMT'             => '0',  // disable legacy mgmt pusher
            ];

            // Build KEY='val' assignments
            $assigns = implode(' ', array_map(
                fn ($k, $v) => $k . '=' . escapeshellarg($v),
                array_keys($env),
                array_values($env)
            ));

            // Mask sensitive bits for logs only
            $maskedAssigns = str_replace(
                [$panelToken, $vpnPass],
                ['***TOKEN***', '***PASS***'],
                $assigns
            );
            Log::info('🔧 Remote env header (masked): ' . $maskedAssigns . ' [ssh …]');

            // Build one remote bash string and run with bash -lc
            $remoteBash = <<<BASH
set -e
export {$assigns}
bash -se <<'SCRIPT_EOF'
{$script}
SCRIPT_EOF
echo EXIT_CODE:$?
BASH;

            $remoteCmd = $sshCmdBase . ' ' . escapeshellarg('bash -lc ' . escapeshellarg($remoteBash));

            // Stream the remote output
            [$exitCode, $combined] = $this->runAndStream($remoteCmd);

            // Prefer explicit marker if present
            if (preg_match('/EXIT_CODE:(\d+)/', $combined, $m)) {
                $exitCode = (int) $m[1];
            }

            $status   = $exitCode === 0 ? 'succeeded' : 'failed';
            $finalLog = $this->stripNoise($combined);

            if ($exitCode === 0) {
                $finalLog .= "\n✅ Deployment succeeded";

                // Attach any existing active users to this server
                $existingUsers = VpnUser::where('is_active', true)->get();
                if ($existingUsers->isNotEmpty()) {
                    $this->vpnServer->vpnUsers()->syncWithoutDetaching($existingUsers->pluck('id')->all());
                    $finalLog .= "\n👥 Auto-assigned {$existingUsers->count()} existing users to server";
                }

                // Kick one immediate status push (best-effort) so UI updates instantly
                @exec($sshCmdBase . ' ' . escapeshellarg('systemctl start ovpn-status-push.service'));
                // Keep your credential sync if you use it elsewhere
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

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    private function buildSshBase(string $sshType, string $user, string $ip, int $port): ?string
    {
        $sshOpts = implode(' ', [
            '-o StrictHostKeyChecking=no',
            '-o UserKnownHostsFile=/dev/null',
            '-o ConnectTimeout=30',
            '-o ServerAliveInterval=15',
            '-o ServerAliveCountMax=4',
            '-p ' . $port,
        ]);

        if ($sshType === 'password') {
            $haveSshpass = trim((string) shell_exec('command -v sshpass || true'));
            if ($haveSshpass === '') {
                $this->failWith('❌ sshpass is required on the panel host for password auth. Install it or use key auth.');
                return null;
            }
            $sshPass = (string) $this->vpnServer->ssh_password;
            if ($sshPass === '') {
                $this->failWith('❌ SSH password not set for password auth.');
                return null;
            }
            return 'sshpass -p ' . escapeshellarg($sshPass)
                 . ' ssh ' . $sshOpts . ' ' . escapeshellarg("{$user}@{$ip}");
        }

        // key auth
        $keyValue = (string) ($this->vpnServer->ssh_key ?: 'id_rsa');
        $keyPath  = str_starts_with($keyValue, '/') || str_contains($keyValue, ':\\')
            ? $keyValue
            : storage_path('app/ssh_keys/' . $keyValue);

        if (!is_file($keyPath)) {
            $this->failWith("❌ SSH key not found at {$keyPath}");
            return null;
        }
        @chmod($keyPath, 0600);

        return 'ssh -i ' . escapeshellarg($keyPath)
             . ' ' . $sshOpts . ' ' . escapeshellarg("{$user}@{$ip}");
    }

    /**
     * Run a long SSH command and stream output into the server's log.
     *
     * @return array{0:int,1:string} [exitCode, combinedOutput]
     */
    private function runAndStream(string $remoteCmd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($remoteCmd, $descriptors, $pipes, base_path());

        if (!is_resource($proc)) {
            $this->failWith('❌ Failed to open SSH process');
            return [1, ''];
        }

        fclose($pipes[0]); // nothing to send to STDIN

        $out = '';
        $err = '';
        $streams = [$pipes[1], $pipes[2]];
        $map = [(int) $pipes[1] => 'OUT', (int) $pipes[2] => 'ERR'];

        $start = time();
        // Let the loop breathe: align to job timeout (minus a margin)
        $maxDuration = max(60, ($this->timeout ?? 900) - 30);

        while (!empty($streams)) {
            if ((time() - $start) > $maxDuration) {
                $this->failWith("❌ Timeout while reading deployment output");
                foreach ($streams as $s) @fclose($s);
                @proc_terminate($proc);
                return [1, $out . $err];
            }

            $read = $streams; $write = $except = null;
            $select = @stream_select($read, $write, $except, 10);

            if ($select === false) {
                $this->failWith("❌ stream_select() failed during SSH session");
                foreach ($streams as $s) @fclose($s);
                @proc_terminate($proc);
                return [1, $out . $err];
            }

            foreach ($read as $r) {
                $line = fgets($r);
                if ($line === false) {
                    fclose($r);
                    unset($streams[array_search($r, $streams, true)]);
                    continue;
                }

                $clean = rtrim($line, "\r\n");
                $this->vpnServer->appendLog($clean);

                if ($map[(int) $r] === 'OUT') {
                    $out .= $line;
                } else {
                    $err .= $line;
                }
            }
        }

        $exitCode = proc_close($proc);
        return [$exitCode, ($this->vpnServer->deployment_log ?? '') . $out . $err];
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
            'deployment_log'    => trim(($this->vpnServer->deployment_log ?? '') . "\n" . $message),
            'status'            => 'offline',
        ]);
    }

    private function stripNoise(string $log): string
    {
        $lines = explode("\n", $log);
        $keep  = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if (preg_match('/^(Generating DH parameters|DH parameters appear to be ok|DEPRECATED OPTION)/i', $t)) continue;
            $keep[] = $t;
        }
        return implode("\n", $keep);
    }
}