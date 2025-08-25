<?php

namespace App\Console\Commands;

use App\Events\ServerMgmtEvent;
use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateVpnStatus extends Command
{
    protected $signature = 'vpn:update-status {--server_id=}';
    protected $description = 'Update OpenVPN connection status for all (or one) servers.';

    public function handle(): int
    {
        $serverId = $this->option('server_id');

        $q = VpnServer::query()->where('deployment_status', 'succeeded');
        if ($serverId) {
            $q->where('id', (int) $serverId);
        }

        $servers = $q->get();
        if ($servers->isEmpty()) {
            $this->warn('No succeeded VPN servers found.');
            return self::SUCCESS;
        }

        Log::info('🔄 Hybrid sync: updating VPN connection status' . ($serverId ? " (server $serverId)" : ' (fleet)'));

        foreach ($servers as $server) {
            try {
                // 1) try mgmt socket
                $raw = $this->readStatusFromMgmt($server);

                // 2) fallback to files
                if ($raw === '') {
                    $raw = $this->readStatusFromFiles($server);
                }

                if ($raw === '') {
                    Log::warning("⚠️ {$server->name}: status not readable from file or mgmt.");
                    // Broadcast empty snapshot (keeps UI responsive)
                    $this->broadcastUsers($server->id, []);
                    // Optional: keep counters fresh
                    $server->forceFill([
                        'online_users' => 0,
                        'last_sync_at' => now(),
                    ])->saveQuietly();
                    continue;
                }

                // Parse status (auto-detects v3/v2)
                $parsed = OpenVpnStatusParser::parse($raw);

                // Build rich users payload for the dashboard
                // (your Alpine code will gracefully format / fallback where needed)
                $users = [];
                foreach ($parsed['clients'] ?? [] as $c) {
                    $username = (string)($c['username'] ?? '');
                    if ($username === '') {
                        continue;
                    }
                    $users[] = [
                        'username'       => $username,
                        'client_ip'      => $c['client_ip']      ?? null,
                        'virtual_ip'     => $c['virtual_ip']     ?? null,
                        'bytes_received' => (int)($c['bytes_received'] ?? 0),
                        'bytes_sent'     => (int)($c['bytes_sent'] ?? 0),
                        'connected_at'   => isset($c['connected_at'])
                            ? now()->setTimestamp($c['connected_at'])->toIso8601String()
                            : null,
                    ];
                }

                // persist quick counters (quietly)
                $server->forceFill([
                    'online_users' => count($users),
                    'last_sync_at' => now(),
                ])->saveQuietly();

                // Broadcast rich snapshot (ServerMgmtEvent handles both array & count styles)
                $this->broadcastUsers($server->id, $users);
            } catch (\Throwable $e) {
                Log::warning("⚠️ {$server->name}: status read failed – {$e->getMessage()}");
                $this->broadcastUsers($server->id, []);
            }
        }

        Log::info('✅ Hybrid sync completed');
        return self::SUCCESS;
    }

    /**
     * Read full status from the management socket.
     */
    private function readStatusFromMgmt(VpnServer $server): string
    {
        $ssh = $server->getSshCommand();

        // Ask mgmt for status v3, then quit. Small sleep lets OpenVPN flush.
        $script = <<<'BASH'
set -e
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
{ printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } \
  | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null || true
BASH;

        $cmd = $ssh . " bash -s <<'BASH'\n" . $script . "\nBASH";

        [$status, $out] = $this->runCmd($cmd);
        if ($status !== 0) {
            Log::warning("⚠️ {$server->name}: mgmt socket read failed");
            return '';
        }

        $raw = trim(implode("\n", $out));
        if ($raw !== '') {
            Log::info("📡 {$server->name}: read status via mgmt :7505");
        }
        return $raw;
    }

    /**
     * Read status from common file paths (v3 / v2).
     */
    private function readStatusFromFiles(VpnServer $server): string
    {
        $ssh = $server->getSshCommand();

        $candidates = array_values(array_unique(array_filter([
            $server->status_log_path,            // per-server override
            '/run/openvpn/server.status',        // systemd template default
            '/run/openvpn/openvpn.status',
            '/run/openvpn/server/server.status', // some distros nest it
            '/var/log/openvpn-status.log',       // classic v2
        ], fn ($p) => is_string($p) && $p !== '')));

        foreach ($candidates as $path) {
            $remote = 'test -r ' . escapeshellarg($path) . ' && cat ' . escapeshellarg($path) . ' || echo "__NOFILE__"';
            $cmd    = $ssh . ' ' . $remote;

            [$status, $out] = $this->runCmd($cmd);
            if ($status !== 0) {
                Log::warning("⚠️ {$server->name}: {$path} not found or empty");
                continue;
            }

            $raw = trim(implode("\n", $out));
            if ($raw !== '' && $raw !== '__NOFILE__') {
                Log::info("📄 {$server->name}: using {$path}");
                return $raw;
            }

            Log::warning("⚠️ {$server->name}: {$path} not found or empty");
        }

        return '';
    }

    /**
     * Run a shell command and return [exitCode, array-of-lines].
     */
    protected function runCmd(string $cmd): array
    {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $p = proc_open($cmd, $desc, $pipes);
        if (!is_resource($p)) {
            return [255, ['proc_open failed']];
        }

        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $rc  = proc_close($p);

        $lines = [];
        if ($out !== '') $lines = array_filter(explode("\n", rtrim($out)));
        if ($err !== '') $lines[] = "STDERR: " . $err;

        return [$rc, $lines];
    }

    /**
     * Broadcast a snapshot using rich users payload (UI supports both shapes).
     */
    private function broadcastUsers(int $serverId, array $users): void
    {
        broadcast(new ServerMgmtEvent(
            $serverId,
            now()->toIso8601String(),
            $users,      // ← array payload (preferred, gives Virtual IP, Connected since, Data transfer)
            null,
            'sync-job'
        ));
    }
}