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
        if ($serverId) $q->where('id', (int) $serverId);

        $servers = $q->get();
        if ($servers->isEmpty()) {
            $this->warn('No succeeded VPN servers found.');
            return self::SUCCESS;
        }

        Log::info('🔄 Hybrid sync: updating VPN connection status' . ($serverId ? " (server $serverId)" : ' (fleet)'));

        foreach ($servers as $server) {
            try {
                $raw = $this->readStatusFromMgmt($server);
                if ($raw === '') {
                    $raw = $this->readStatusFromFiles($server);
                }

                if ($raw === '') {
                    Log::warning("⚠️ {$server->name}: status not readable from file or mgmt.");
                    $this->broadcast($server->id, now()->toAtomString(), 0, '');
                    continue;
                }

                $parsed = OpenVpnStatusParser::parse($raw);
                $users  = collect($parsed['clients'] ?? [])->pluck('username')->filter()->values()->all();
                $count  = count($users);
                $cnList = implode(',', $users);

                // optional: persist quick counters without noise
                $server->forceFill([
                    'online_users' => $count,
                    'last_sync_at' => now(),
                ])->saveQuietly();

                $this->broadcast($server->id, now()->toAtomString(), $count, $cnList);
            } catch (\Throwable $e) {
                Log::warning("⚠️ {$server->name}: status read failed – {$e->getMessage()}");
                $this->broadcast($server->id, now()->toAtomString(), 0, '');
            }
        }

        Log::info('✅ Hybrid sync completed');
        return self::SUCCESS;
    }

    /**
     * Management-socket reader (preferred).
     */
    private function readStatusFromMgmt(VpnServer $server): string
    {
        // Build SSH command from the model
        $ssh = $server->getSshCommand();

        // Small script that queries mgmt and prints the full status block
        $script = <<<'BASH'
set -e
MGMT_HOST="${MGMT_HOST:-127.0.0.1}"
MGMT_PORT="${MGMT_PORT:-7505}"
# status 3 then quit; small sleep ensures OpenVPN flushes output
{ printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } \
  | nc -w 2 "$MGMT_HOST" "$MGMT_PORT" 2>/dev/null || true
BASH;

        $cmd = $ssh . " bash -s <<'BASH'\n" . $script . "\nBASH";

        [$status, $out] = $this->run($cmd);
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
     * File readers (fallback) — tries common v3/v2 paths.
     */
    private function readStatusFromFiles(VpnServer $server): string
    {
        $ssh = $server->getSshCommand();

        // Try a few candidates, including custom per-server path
        $candidates = array_values(array_unique(array_filter([
            $server->status_log_path,                 // per-server override
            '/run/openvpn/server.status',             // systemd template default
            '/run/openvpn/openvpn.status',
            '/run/openvpn/server/server.status',      // some distros use nested dir
            '/var/log/openvpn-status.log',            // classic v2
        ], fn($p) => is_string($p) && $p !== '')));

        foreach ($candidates as $path) {
            $remote = 'test -r ' . escapeshellarg($path) . ' && cat ' . escapeshellarg($path) . ' || echo "__NOFILE__"';
            $cmd    = $ssh . ' ' . $remote;

            [$status, $out] = $this->run($cmd);
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
     * Minimal runner that returns [exitCode, lines[]].
     */
        /**
     * Minimal runner that returns [exitCode, lines[]].
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

    private function broadcast(int $serverId, string $tsIso, int $count, string $cnCsv): void
    {
        // Reverb/Echo event used by your dashboard
        broadcast(new ServerMgmtEvent(
            $serverId,
            $tsIso,
            $count,
            $cnCsv,
            'sync-job'
        ));
    }
}