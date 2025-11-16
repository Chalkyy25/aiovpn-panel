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
use App\Services\GeoIpService; // 👈 NEW

class DeployVpnServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900;
    public $tries = 2;

    public VpnServer $vpnServer;

    private ?string $lastKeyPath = null;
    private ?string $lastKeyFingerprint = null;

    public function __construct(VpnServer $vpnServer)
    {
        $this->vpnServer = $vpnServer;
    }

    // 👇 CHANGED: inject GeoIpService so we can update country/city automatically
    public function handle(GeoIpService $geo): void
    {
        Log::info("🚀 DeployVpnServer: starting for #{$this->vpnServer->id}");

        if ($this->vpnServer->is_deploying) {
            Log::warning("⚠️ DeployVpnServer: already deploying #{$this->vpnServer->id}");
            return;
        }

        $panelUrl   = rtrim((string) config('services.panel.base'), '/');
        $panelToken = (string) config('services.panel.token');

        if ($panelUrl === '' || $panelToken === '') {
            $this->failWith('❌ PANEL config missing: set PANEL_BASE_URL and PANEL_TOKEN in .env then php artisan config:cache');
            return;
        }

        $ip   = (string) $this->vpnServer->ip_address;
        $port = (int) ($this->vpnServer->ssh_port ?: 22);
        $user = (string) ($this->vpnServer->ssh_user ?: 'root');

        if ($ip === '' || $user === '') {
            $this->failWith('❌ Server IP or SSH user is missing');
            return;
        }

        // Model’s preferred proto/port (still respected for the UDP instance)
        $modelProto = strtolower((string) ($this->vpnServer->protocol ?: 'udp'));
        $vpnProto   = $modelProto === 'tcp' ? 'tcp' : 'udp';
        $vpnPort    = (int) ($this->vpnServer->port ?: 1194);

        $mgmtHost = '127.0.0.1';
        $mgmtPort = (int) ($this->vpnServer->mgmt_port ?: 7505);
        $wgPort   = 51820;

        // Prefer new script name; fallback to legacy path for compatibility
        $scriptPath = base_path('resources/scripts/deploy-vpn.sh');
        if (!is_file($scriptPath)) {
            $legacy = base_path('resources/scripts/deploy-openvpn.sh');
            if (is_file($legacy)) $scriptPath = $legacy;
        }
        if (!is_file($scriptPath)) {
            $this->failWith("❌ Missing deployment script at {$scriptPath}");
            return;
        }
        $script = file_get_contents($scriptPath);

        // Seed/Reuse VPN user
        $vpnUser = 'admin';
        $vpnPass = substr(bin2hex(random_bytes(16)), 0, 16);
        if ($existing = $this->vpnServer->vpnUsers()->where('is_active', true)->first()) {
            $vpnUser = (string) $existing->username;
            $vpnPass = (string) ($existing->plain_password ?: $vpnPass);
            Log::info("🔑 Reusing existing active user: {$vpnUser}");
        } else {
            Log::info("🔑 Seeding VPN user: {$vpnUser}");
        }

        $this->vpnServer->update([
            'is_deploying'      => true,
            'deployment_status' => 'running',
            'deployment_log'    => "🚀 Starting deployment on {$ip}…\n",
        ]);

        try {
            $sshCmdBase = $this->buildSshBase($user, $ip, $port);
            if ($sshCmdBase === null) {
                return;
            }

            // Quick SSH sanity
            $testCmd = $sshCmdBase . ' ' . escapeshellarg('echo CONNECTION_OK') . ' 2>&1';
            $testOutput = [];
            $testStatus = 0;
            exec($testCmd, $testOutput, $testStatus);
            $testText = trim(implode("\n", $testOutput));
            Log::info("🧪 SSH test cmd: {$testCmd}");
            if ($this->lastKeyFingerprint) Log::info("🧪 Using key fingerprint: {$this->lastKeyFingerprint}");
            Log::info("🧪 SSH test out:\n" . $testText);

            if ($testStatus !== 0 || strpos($testText, 'CONNECTION_OK') === false) {
                if (str_contains($testText, 'Permission denied (publickey)')) {
                    Log::warning('🔐 Publickey failed — attempting legacy fallback to seed DeployKey');

                    $legacyBase = $this->buildLegacySshBase($user, $ip, $port, $this->lastKeyPath);
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
                    $this->failWith("❌ SSH connection failed to {$ip}\n{$testText}");
                    return;
                }
            }
            Log::info("✅ SSH test OK for {$ip}");

            // Ensure our DeployKey is present
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
            } catch (\Throwable) {
                // ignore
            }

            // (Optional) peek authorized_keys first few lines
            try {
                $ak = [];
                exec($sshCmdBase . ' ' . escapeshellarg('head -n 5 /root/.ssh/authorized_keys || true'), $ak);
                if (!empty($ak)) {
                    Log::info("📥 Server authorized_keys (first 5):\n" . implode("\n", array_map('trim', $ak)));
                }
            } catch (\Throwable) {}

            // ── Build environment for the new deploy script
            $vpnIp   = $this->vpnServer->vpn_ip  ?? '10.8.0.1';
            $vpnNet  = $this->vpnServer->vpn_net ?? '10.8.0.0/24';
            $vpnDev  = 'tun0';

            $enablePrivateDns = ($this->vpnServer->enable_private_dns ?? true) ? '1' : '0';

            // interval must be "Ns" even if config returns an int
            $interval = config('services.vpn.status_push_interval', '5s');
            if (is_numeric($interval)) { $interval = "{$interval}s"; }

            // Where clients will dial; prefer server hostname if set
            $ovpnEndpoint = $this->vpnServer->hostname ?: $ip;

            // Per-server toggle (default ON). Add a boolean column enable_tcp_stealth if you want UI control.
            $enableTcpStealth = $this->vpnServer->enable_tcp_stealth ?? true;

            $env = [
                'PANEL_URL'   => $panelUrl,
                'PANEL_TOKEN' => $panelToken,
                'SERVER_ID'   => (string) $this->vpnServer->id,

                // Management
                'MGMT_HOST'   => $mgmtHost,
                'MGMT_PORT'   => (string) $mgmtPort,

                // WireGuard
                'WG_PORT'     => (string) $wgPort,

                // OpenVPN — note OVPN_* (not VPN_*)
                'OVPN_PORT'   => (string) $vpnPort,
                'OVPN_PROTO'  => $vpnProto,
                'OVPN_ENDPOINT_HOST' => $ovpnEndpoint,

                // Stealth TCP/443 instance
                'ENABLE_TCP_STEALTH' => $enableTcpStealth ? '1' : '0',
                'TCP_PORT'           => '443',
                'TCP_SUBNET'         => '10.8.100.0/24',

                // Auth seeding
                'VPN_USER'    => $vpnUser,
                'VPN_PASS'    => $vpnPass,

                // Status push
                'STATUS_PATH'          => '/run/openvpn/server.status',
                'STATUS_PUSH_INTERVAL' => $interval,
                'PANEL_CALLBACKS'      => '1',
                'PUSH_MGMT'            => '0',

                // DNS + misc
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

            $remoteBash = <<<BASH
set -e
export {$assigns}
bash -se <<'SCRIPT_EOF'
{$script}
SCRIPT_EOF
echo EXIT_CODE:$?
BASH;

            $remoteCmd = $sshCmdBase . ' ' . escapeshellarg('bash -lc ' . escapeshellarg($remoteBash));

            [$exitCode, $combined] = $this->runAndStream($remoteCmd);

            if (preg_match('/EXIT_CODE:(\d+)/', $combined, $m)) {
                $exitCode = (int) $m[1];
            }

            $status   = $exitCode === 0 ? 'succeeded' : 'failed';
            $finalLog = $this->stripNoise($combined);

            if ($exitCode === 0) {
                $finalLog .= "\n✅ Deployment succeeded";

                // 🔎 NEW: GeoIP update after successful deploy
                try {
                    $freshServer = $this->vpnServer->fresh();
                    $geoUpdated  = $geo->updateServer($freshServer);

                    if ($geoUpdated) {
                        $finalLog .= "\n🗺 GeoIP: location updated to "
                            . ($freshServer->country_code ?? '??')
                            . ' / '
                            . ($freshServer->city ?? 'Unknown');
                    } else {
                        $finalLog .= "\n🗺 GeoIP: no changes (already set or lookup failed)";
                    }
                } catch (\Throwable $geoE) {
                    Log::warning('⚠️ GeoIP update failed for server #'.$this->vpnServer->id.': '.$geoE->getMessage());
                    $finalLog .= "\n⚠️ GeoIP update error: " . $geoE->getMessage();
                }

                $existingUsers = VpnUser::where('is_active', true)->get();
                if ($existingUsers->isNotEmpty()) {
                    $this->vpnServer->vpnUsers()->syncWithoutDetaching($existingUsers->pluck('id')->all());
                    $finalLog .= "\n👥 Auto-assigned {$existingUsers->count()} existing users to server";
                }

                // Start the TIMER (not the one-shot service); also push once right now
                @exec($sshCmdBase . ' ' . escapeshellarg('systemctl start ovpn-status-push.timer || true'));
                @exec($sshCmdBase . ' ' . escapeshellarg('/usr/local/bin/ovpn-status-push.sh || true'));

                // Keep your existing syncs
                SyncOpenVPNCredentials::dispatch($this->vpnServer);

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

        return $this->buildLegacySshBase($user, $ip, $port);
    }

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

        if ($cmd = $useKey($legacyPath)) return $cmd;

        $dir = storage_path('app/ssh_keys');
        $candidates = [];
        if (is_dir($dir)) {
            foreach (['id_rsa', 'id_ecdsa', 'id_ed25519'] as $base) {
                $p = $dir . '/' . $base;
                if (is_file($p)) $candidates[] = $p;
            }
            foreach (glob($dir . '/*') ?: [] as $p) {
                if (is_file($p) && !preg_match('/\.pub$/', $p) && !in_array($p, $candidates, true)) {
                    $candidates[] = $p;
                }
            }
            $candidates = array_values(array_filter($candidates, function ($p) use ($excludeKeyPath) {
                return !$excludeKeyPath || realpath($p) !== realpath($excludeKeyPath);
            }));
            usort($candidates, function ($a, $b) {
                $rank = fn($x) => str_ends_with($x, 'id_rsa') ? 0 : (str_ends_with($x, 'id_ecdsa') ? 1 : (str_ends_with($x, 'id_ed25519') ? 2 : 3));
                return $rank($a) <=> $rank($b);
            });
            foreach ($candidates as $p) {
                if ($cmd = $useKey($p)) return $cmd;
            }
        }

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

    private function captureKeyDiagnostics(string $privateKeyPath, string $label): void
    {
        $this->lastKeyPath = $privateKeyPath;

        $pubPath = str_ends_with($privateKeyPath, '.pub') ? $privateKeyPath : $privateKeyPath . '.pub';

        if (!is_file($pubPath)) {
            $pub = @shell_exec(sprintf('ssh-keygen -y -f %s 2>/dev/null', escapeshellarg($privateKeyPath))) ?: '';
            $pub = trim($pub);
            if ($pub !== '') {
                file_put_contents($pubPath, $pub . PHP_EOL);
                @chmod($pubPath, 0644);
            }
        }

        $fp = @shell_exec(sprintf('ssh-keygen -lf %s 2:/dev/null', escapeshellarg($pubPath))) ?: '';
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

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $start = time();
        $maxDuration = max(60, ($this->timeout ?? 900) - 10);

        while (true) {
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

            $status = proc_get_status($proc);
            if (($status === false || !$status['running']) && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            if ((time() - $start) > $maxDuration) {
                Log::warning('⏱ runAndStream: soft timeout while reading SSH output');
                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            @stream_select($read, $write, $except, 1);
        }

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

    private function hydrateWireGuardFacts(): void
    {
        $server = $this->vpnServer->fresh();

        $endpoint = $server->wg_endpoint_host ?: $server->ip_address;
        $dirty = false;

        if (!$server->wg_endpoint_host && $endpoint) { $server->wg_endpoint_host = $endpoint; $dirty = true; }
        if (!$server->wg_port) { $server->wg_port = 51820; $dirty = true; }
        if (blank($server->wg_subnet)) { $server->wg_subnet = '10.66.66.0/24'; $dirty = true; }

        if (blank($server->dns)) { $server->dns = '10.66.66.1'; $dirty = true; }

        if ($dirty) {
            $server->saveQuietly();
            \Log::info("🧭 WG facts hydrated for server #{$server->id} (endpoint={$server->wg_endpoint_host}:{$server->wg_port}, dns={$server->dns})");
        }
    }

    private function resyncWireGuardPeers(): int
    {
        $server = $this->vpnServer->fresh(['vpnUsers']);

        $users = $server->vpnUsers()
            ->where('vpn_users.is_active', true)
            ->select([
                'vpn_users.id',
                'vpn_users.username',
                'vpn_users.wireguard_private_key',
                'vpn_users.wireguard_public_key',
                'vpn_users.wireguard_address',
            ])
            ->orderBy('vpn_users.id')
            ->get();

        if ($users->isEmpty()) {
            Log::info("🔁 No active users to WG-sync on server #{$server->id}");
            return 0;
        }

        $serversCount = 1;
        Log::info("🔧 Starting WG sync for {$users->count()} users across {$serversCount} server(s)...");

        $taken = array_fill_keys(
            \App\Models\VpnUser::whereNotNull('wireguard_address')->pluck('wireguard_address')->all(),
            true
        );

        $dispatched = 0;

        foreach ($users as $u) {
            $dirty = false;

            if (blank($u->wireguard_private_key) || blank($u->wireguard_public_key)) {
                $keys = \App\Models\VpnUser::generateWireGuardKeys();
                $u->wireguard_private_key = $keys['private'];
                $u->wireguard_public_key  = $keys['public'];
                $dirty = true;
            }

            if (blank($u->wireguard_address)) {
                for ($i = 2; $i <= 254; $i++) {
                    $candidate = "10.66.66.$i/32";
                    if (!isset($taken[$candidate])) {
                        $u->wireguard_address = $candidate;
                        $taken[$candidate] = true;
                        $dirty = true;
                        break;
                    }
                }
            }

            if ($dirty) {
                $u->saveQuietly();
            }

            dispatch(
                (new \App\Jobs\AddWireGuardPeer($u, $server))
                    ->setQuiet(true)
                    ->onConnection('redis')
                    ->onQueue('wg')
            );

            $dispatched++;
        }

        Log::info("✅ [WG] Added {$dispatched} user(s) across {$serversCount}/{$serversCount} server(s).");
        return $dispatched;
    }
}