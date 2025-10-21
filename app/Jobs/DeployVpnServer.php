<?php

namespace App\Jobs;

use Throwable;
use App\Models\VpnUser;
use App\Models\VpnServer;
use App\Models\DeployKey;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Jobs\SyncOpenVPNCredentials;
use App\Jobs\AddWireGuardPeer;

class DeployVpnServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900;
    public $tries = 2;

    public VpnServer $vpnServer;

    /** For diagnostics */
    private ?string $lastKeyPath = null;         // private key path of DeployKey or legacy key used
    private ?string $lastKeyFingerprint = null;  // fingerprint of that key

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
        $ip   = (string) $this->vpnServer->ip_address;
        $port = (int) ($this->vpnServer->ssh_port ?: 22);
        $user = (string) ($this->vpnServer->ssh_user ?: 'root');

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

        // Load deploy script (your WG-first script; update path if needed)
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
            // SSH base (DeployKey first, then legacy)
            $sshCmdBase = $this->buildSshBase($user, $ip, $port); // DeployKey if available
            if ($sshCmdBase === null) {
                return; // buildSshBase already failed + logged
            }

            // SSH sanity test (capture stderr too)
            $testCmd = $sshCmdBase . ' ' . escapeshellarg('echo CONNECTION_OK') . ' 2>&1';
            $testOutput = [];
            $testStatus = 0;
            exec($testCmd, $testOutput, $testStatus);
            $testText = trim(implode("\n", $testOutput));
            Log::info("🧪 SSH test cmd: {$testCmd}");
            if ($this->lastKeyFingerprint) {
                Log::info("🧪 Using key fingerprint: {$this->lastKeyFingerprint}");
            }
            Log::info("🧪 SSH test out:\n" . $testText);

            if ($testStatus !== 0 || strpos($testText, 'CONNECTION_OK') === false) {
                // If publickey rejected, try to seed our DeployKey .pub using legacy path (password or old key)
                if (str_contains($testText, 'Permission denied (publickey)')) {
                    Log::warning('🔐 Publickey failed — attempting legacy fallback to seed DeployKey');

                    $legacyBase = $this->buildLegacySshBase($user, $ip, $port, $this->lastKeyPath /*exclude*/);
                    if ($legacyBase) {
                        $deployPriv = $this->lastKeyPath ?? '';
                        if ($deployPriv === '' || !is_file($deployPriv)) {
                            $this->failWith('❌ DeployKey private path unknown; cannot seed.');
                            return;
                        }
                        if (!$this->trySeedDeployKey($legacyBase, $deployPriv)) {
                            $this->failWith("❌ Could not seed DeployKey to {$ip} using legacy access.");
                            return;
                        }

                        // Retry with DeployKey
                        $retryOut = []; $retryRc = 0;
                        exec($testCmd, $retryOut, $retryRc);
                        $retryText = trim(implode("\n", $retryOut));
                        Log::info("🧪 SSH retry out:\n" . $retryText);
                        if ($retryRc !== 0 || strpos($retryText, 'CONNECTION_OK') === false) {
                            $this->failWith("❌ SSH connection still failing to {$ip} after seeding.\n{$retryText}");
                            return;
                        }
                        Log::info('✅ SSH retry with DeployKey succeeded after seeding');
                    } else {
                        // No legacy path available — provide an actionable hint and fail.
                        $pubPath = $this->lastKeyPath ? ($this->lastKeyPath . '.pub') : '(unknown)';
                        $pubPreview = (is_file($pubPath) ? substr(trim(file_get_contents($pubPath)), 0, 80) . '…' : '');
                        $hint = "\n🛠 Fix:\n"
                              . "  1) Add this public key to /root/.ssh/authorized_keys on {$ip}\n"
                              . "     {$pubPath}\n     {$pubPreview}\n"
                              . "  2) Permissions: chmod 700 /root/.ssh && chmod 600 /root/.ssh/authorized_keys\n";
                        $this->failWith("❌ SSH connection failed to {$ip}\n{$testText}{$hint}");
                        return;
                    }
                } else {
                    // Not a publickey issue: fail with original diagnostics
                    $this->failWith("❌ SSH connection failed to {$ip}\n{$testText}");
                    return;
                }
            }
            Log::info("✅ SSH test OK for {$ip}");

            // Best-effort: ensure our DeployKey .pub is present on target (idempotent)
            try {
                $pubPath = $this->lastKeyPath ? $this->lastKeyPath . '.pub' : null;
                if ($pubPath && is_file($pubPath)) {
                    $pub = trim((string) file_get_contents($pubPath));
                    if ($pub !== '') {
                        $ensureCmd = "set -e; install -d -m 700 /root/.ssh; "
                                   . "touch /root/.ssh/authorized_keys; chmod 600 /root/.ssh/authorized_keys; "
                                   . "grep -qxF " . escapeshellarg($pub) . " /root/.ssh/authorized_keys || echo "
                                   . escapeshellarg($pub) . " >> /root/.ssh/authorized_keys";
                        @exec($sshCmdBase . ' ' . escapeshellarg($ensureCmd));
                        Log::info('🔑 Ensured DeployKey is present on target');
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal
            }

            // Optional: peek server authorized_keys (first 5 lines)
            try {
                $ak = [];
                exec($sshCmdBase . ' ' . escapeshellarg('head -n 5 /root/.ssh/authorized_keys || true'), $ak);
                if (!empty($ak)) {
                    Log::info("📥 Server authorized_keys (first 5):\n" . implode("\n", array_map('trim', $ak)));
                }
            } catch (\Throwable) {
                // ignore
            }

            // Remote env (incl. Private DNS controls)
            $vpnIp   = $this->vpnServer->vpn_ip  ?? '10.8.0.1';
            $vpnNet  = $this->vpnServer->vpn_net ?? '10.8.0.0/24';
            $vpnDev  = 'tun0'; // OpenVPN uses tun0; switch to 'wg0' if you deploy WG-only
            $enablePrivateDns = ($this->vpnServer->enable_private_dns ?? true) ? '1' : '0';

            $env = [
                // Panel
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

                // status agent
                'STATUS_PATH'          => '/run/openvpn/server.status',
                'STATUS_PUSH_INTERVAL' => (string) (config('services.vpn.status_push_interval', 5)),
                'PANEL_CALLBACKS'      => '1',
                'PUSH_MGMT'            => '0',

                // Private DNS
                'ENABLE_PRIVATE_DNS'   => $enablePrivateDns,
                'VPN_IP'               => $vpnIp,
                'VPN_NET'              => $vpnNet,
                'VPN_DEV'              => $vpnDev,
            ];

            $assigns = implode(' ', array_map(
                fn ($k, $v) => $k . '=' . escapeshellarg($v),
                array_keys($env),
                array_values($env)
            ));

            $maskedAssigns = str_replace([$panelToken, $vpnPass], ['***TOKEN***', '***PASS***'], $assigns);
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

                // Kick one immediate status push (best-effort)
                @exec($sshCmdBase . ' ' . escapeshellarg('systemctl start ovpn-status-push.service'));

                // OpenVPN creds (existing behavior)
                SyncOpenVPNCredentials::dispatch($this->vpnServer);

                // ─────────────────────────────────────────────────────────
                // WireGuard: hydrate facts + resync peers (idempotent)
                // ─────────────────────────────────────────────────────────
                if (config('services.wireguard.resync_on_deploy', true)) {
                    try {
                        $this->hydrateWireGuardFacts();
                        $resynced = $this->resyncWireGuardPeers();
                        $finalLog .= "\n🔁 WG resync queued for {$resynced} user(s)";
                    } catch (\Throwable $wgE) {
                        Log::warning('⚠️ WG resync threw: '.$wgE->getMessage());
                        $finalLog .= "\n⚠️ WG resync warning: ".$wgE->getMessage();
                    }
                }
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

    /** Build the SSH base command (DeployKey preferred). */
    private function buildSshBase(string $user, string $ip, int $port): ?string
    {
        $sshOpts = implode(' ', [
            '-o IdentitiesOnly=yes',
            '-o PreferredAuthentications=publickey',
            '-o StrictHostKeyChecking=accept-new',
            '-o UserKnownHostsFile=/dev/null',
            '-o GlobalKnownHostsFile=/dev/null',
            '-o ConnectTimeout=10',
            '-o ServerAliveInterval=15',
            '-o ServerAliveCountMax=3',
            '-p ' . $port,
        ]);

        // 1) DB DeployKey (preferred)
        $dk = $this->vpnServer->deployKey ?: DeployKey::active()->first();
        if ($dk) {
            $keyPath = $dk->privateAbsolutePath();
            if (!is_file($keyPath)) {
                $this->failWith("❌ DeployKey not found at {$keyPath} (name={$dk->name})");
                return null;
            }
            @chmod($keyPath, 0600);

            $this->captureKeyDiagnostics($keyPath, 'DeployKey');
            return 'ssh -i ' . escapeshellarg($keyPath) . ' ' . $sshOpts . ' ' . escapeshellarg("{$user}@{$ip}");
        }

        // 2) Legacy: password or key
        return $this->buildLegacySshBase($user, $ip, $port);
    }

    /** Build a legacy SSH base (password or legacy key) for seeding, excluding a given key path. */
    private function buildLegacySshBase(string $user, string $ip, int $port, ?string $excludeKeyPath = null): ?string
    {
        $sshOpts = implode(' ', [
            '-o StrictHostKeyChecking=accept-new',
            '-o UserKnownHostsFile=/dev/null',
            '-o GlobalKnownHostsFile=/dev/null',
            '-o ConnectTimeout=10',
            '-o ServerAliveInterval=15',
            '-o ServerAliveCountMax=3',
            '-p ' . $port,
        ]);

        // 1) Prefer explicit legacy key on the model if it exists and is NOT the deploy key
        $legacyName  = (string) ($this->vpnServer->ssh_key ?: '');
        $legacyPath  = $legacyName
            ? (str_starts_with($legacyName, '/') || str_contains($legacyName, ':\\')
                ? $legacyName
                : storage_path('app/ssh_keys/' . $legacyName))
            : '';

        $useKey = function (?string $path) use ($excludeKeyPath, $sshOpts, $user, $ip) {
            if (!$path || !is_file($path)) return null;
            if ($excludeKeyPath && realpath($path) === realpath($excludeKeyPath)) return null;
            @chmod($path, 0600);
            Log::warning("🪪 Using legacy key as fallback: {$path}");
            return 'ssh -i ' . escapeshellarg($path) . ' ' . $sshOpts . ' ' . escapeshellarg("{$user}@{$ip}");
        };

        // try the model’s legacy key (if different from DeployKey)
        if ($cmd = $useKey($legacyPath)) return $cmd;

        // 2) Otherwise, search storage/app/ssh_keys for something that isn’t the DeployKey; prefer id_rsa
        $dir = storage_path('app/ssh_keys');
        $candidates = [];
        if (is_dir($dir)) {
            foreach (['id_rsa', 'id_ecdsa', 'id_ed25519'] as $base) {
                $p = $dir . '/' . $base;
                if (is_file($p)) $candidates[] = $p;
            }
            // Also consider any other private keys present
            foreach (glob($dir . '/*') ?: [] as $p) {
                if (is_file($p) && !preg_match('/\.pub$/', $p) && !in_array($p, $candidates, true)) {
                    $candidates[] = $p;
                }
            }
            // Filter out the deploy key path
            $candidates = array_values(array_filter($candidates, function ($p) use ($excludeKeyPath) {
                return !$excludeKeyPath || realpath($p) !== realpath($excludeKeyPath);
            }));
            // Prefer id_rsa if present
            usort($candidates, function ($a, $b) {
                $rank = fn($x) => str_ends_with($x, 'id_rsa') ? 0 : (str_ends_with($x, 'id_ecdsa') ? 1 : (str_ends_with($x, 'id_ed25519') ? 2 : 3));
                return $rank($a) <=> $rank($b);
            });
            foreach ($candidates as $p) {
                if ($cmd = $useKey($p)) return $cmd;
            }
        }

        // 3) Password fallback (if configured)
        $sshType = (string) ($this->vpnServer->ssh_type ?: 'key');
        if ($sshType === 'password') {
            $sshPass = (string) $this->vpnServer->ssh_password;
            $haveSshpass = trim((string) shell_exec('command -v sshpass || true'));
            if ($sshPass !== '' && $haveSshpass !== '') {
                Log::warning('🪪 Using password SSH as legacy fallback');
                return 'sshpass -p ' . escapeshellarg($sshPass) . ' ssh ' . $sshOpts . ' ' . escapeshellarg("{$user}@{$ip}");
            }
        }

        return null;
    }

    /** Append DeployKey .pub into /root/.ssh/authorized_keys over a working legacy session. */
    private function trySeedDeployKey(string $legacySshBase, string $deployPrivKeyPath): bool
    {
        $pubPath = str_ends_with($deployPrivKeyPath, '.pub') ? $deployPrivKeyPath : $deployPrivKeyPath . '.pub';
        if (!is_file($pubPath)) {
            $pub = @shell_exec(sprintf('ssh-keygen -y -f %s 2>/dev/null', escapeshellarg($deployPrivKeyPath))) ?: '';
            $pub = trim($pub);
            if ($pub === '') { Log::error('❌ Could not derive .pub from DeployKey'); return false; }
            file_put_contents($pubPath, $pub . PHP_EOL);
            @chmod($pubPath, 0644);
        }
        $pub = trim((string) file_get_contents($pubPath));
        if ($pub === '') { Log::error('❌ Empty DeployKey .pub'); return false; }

        $seedCmd = "set -e;"
            . " install -d -m 700 /root/.ssh;"
            . " touch /root/.ssh/authorized_keys; chmod 600 /root/.ssh/authorized_keys;"
            . " grep -qxF " . escapeshellarg($pub) . " /root/.ssh/authorized_keys || echo " . escapeshellarg($pub) . " >> /root/.ssh/authorized_keys";

        $cmd = $legacySshBase . ' ' . escapeshellarg($seedCmd);
        exec($cmd, $o, $rc);
        if ($rc === 0) {
            Log::info('🔑 Seeded DeployKey public key into authorized_keys via legacy session');
            return true;
        }
        Log::error("❌ Failed to seed DeployKey via legacy session (rc={$rc})");
        return false;
    }

    /** Capture + log key fingerprint/comment for diagnostics. */
    private function captureKeyDiagnostics(string $privateKeyPath, string $label): void
    {
        $this->lastKeyPath = $privateKeyPath;

        // Ensure we have a .pub and fingerprint
        $pubPath = str_ends_with($privateKeyPath, '.pub') ? $privateKeyPath : $privateKeyPath . '.pub';

        if (!is_file($pubPath)) {
            $pub = @shell_exec(sprintf('ssh-keygen -y -f %s 2>/dev/null', escapeshellarg($privateKeyPath))) ?: '';
            $pub = trim($pub);
            if ($pub !== '') {
                file_put_contents($pubPath, $pub . PHP_EOL); // <-- fixed concatenation
                @chmod($pubPath, 0644);
            }
        }

        $fp = @shell_exec(sprintf('ssh-keygen -lf %s 2>/dev/null', escapeshellarg($pubPath))) ?: '';
        $fp = trim($fp);
        $this->lastKeyFingerprint = $fp ?: null;

        Log::info("🔐 Using {$label}: {$privateKeyPath}");
        if ($fp) Log::info("🔎 Key fingerprint: {$fp}");
        if (is_file($pubPath)) {
            $pubData = trim((string) @file_get_contents($pubPath));
            if ($pubData !== '') {
                $comment = trim(substr($pubData, strrpos($pubData, ' ') + 1));
                Log::info("📝 Key comment: {$comment}");
            }
        }
    }

    /** Run a long SSH command and stream output into the server's log. */
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

        // nothing to send
        fclose($pipes[0]);

        // non-blocking reads
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $start = time();
        $maxDuration = max(60, ($this->timeout ?? 900) - 10);

        while (true) {
            // read whatever is available
            $chunkOut = stream_get_contents($pipes[1]);
            $chunkErr = stream_get_contents($pipes[2]);
            if ($chunkOut !== false && $chunkOut !== '') {
                foreach (explode("\n", $chunkOut) as $line) {
                    $line = rtrim($line, "\r");
                    if ($line !== '') $this->vpnServer->appendLog($line);
                }
                $out .= $chunkOut;
            }
            if ($chunkErr !== false && $chunkErr !== '') {
                foreach (explode("\n", $chunkErr) as $line) {
                    $line = rtrim($line, "\r");
                    if ($line !== '') $this->vpnServer->appendLog($line);
                }
                $err .= $chunkErr;
            }

            // if both streams are at EOF, we can stop
            $status = proc_get_status($proc);
            if (($status === false || !$status['running']) && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            // timeout guard
            if ((time() - $start) > $maxDuration) {
                Log::warning('⏱ runAndStream: soft timeout while reading SSH output');
                break;
            }

            // light wait
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            @stream_select($read, $write, $except, 1);
        }

        // close pipes
        foreach ([$pipes[1], $pipes[2]] as $p) { if (is_resource($p)) @fclose($p); }

        $exitCode = proc_close($proc);
        return [$exitCode, ($this->vpnServer->deployment_log ?? '') . $out . $err];
    }

    private function failWith(string $message, Throwable $e = null): void
    {
        Log::error($message);
        if ($e) Log::error($e);

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

    // ─────────────────────────────────────────────────────────────
    // WireGuard helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Ensure server WG facts are sane post-deploy (endpoint & private DNS).
     * Endpoint: prefer wg_endpoint_host, fall back to server IP
     * DNS: set wg_dns_ip to 10.66.66.1 (your Unbound on wg0)
     */
    private function hydrateWireGuardFacts(): void
{
    $server = $this->vpnServer->fresh();

    // Prefer existing endpoint host, otherwise fall back to server IP
    $endpoint = $server->wg_endpoint_host ?: $server->ip_address;

    $dirty = false;

    if (!$server->wg_endpoint_host && $endpoint) {
        $server->wg_endpoint_host = $endpoint;
        $dirty = true;
    }
    if (!$server->wg_port) {
        $server->wg_port = 51820;
        $dirty = true;
    }

    // Put private-DNS IP into the existing 'dns' field if it's empty.
    // Your WireGuardConfigBuilder uses $server->dns already.
    if (blank($server->dns)) {
        // Unbound on wg0 is bound to server's wg IP (10.66.66.1)
        $server->dns = '10.66.66.1';
        $dirty = true;
    }

    if ($dirty) {
        $server->saveQuietly();
        \Log::info("🧭 WG facts hydrated for server #{$server->id} (endpoint={$server->wg_endpoint_host}:{$server->wg_port}, dns={$server->dns})");
    }
}

    /**
     * Re-push WG peers for all active, attached users (idempotent).
     * Returns the number of jobs dispatched.
     */
    private function resyncWireGuardPeers(): int
    {
        $server = $this->vpnServer->fresh(['vpnUsers']);
        $users  = $server->vpnUsers()
            ->where('is_active', true)
            ->get(['id','username','wireguard_private_key','wireguard_public_key','wireguard_address','is_active']);

        $count = 0;

        foreach ($users as $u) {
            $dirty = false;

            // Ensure keys present (use your model helper)
            if (blank($u->wireguard_private_key) || blank($u->wireguard_public_key)) {
                $keys = \App\Models\VpnUser::generateWireGuardKeys();
                $u->wireguard_private_key = $keys['private'];
                $u->wireguard_public_key  = $keys['public'];
                $dirty = true;
            }

            // Ensure address present (simple allocator in /24)
            if (blank($u->wireguard_address)) {
                $taken = \App\Models\VpnUser::whereNotNull('wireguard_address')->pluck('wireguard_address')->all();
                for ($i=2; $i<=254; $i++) {
                    $candidate = "10.66.66.$i/32";
                    if (!in_array($candidate, $taken, true)) { $u->wireguard_address = $candidate; $dirty = true; break; }
                }
            }

            if ($dirty) $u->saveQuietly();

            // Push peer (your job should update/replace existing peer)
            AddWireGuardPeer::dispatch($u);
            $count++;
        }

        Log::info("🔁 Dispatched WG peer sync for {$count} user(s) on server #{$server->id}");
        return $count;
    }
}