<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class UpdateVpnConnectionStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    protected ?int $serverId;
    protected bool $strictOfflineOnMissing = false;
    protected bool $verboseMgmtLog;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
        $this->verboseMgmtLog = (bool) (config('app.env') !== 'production'
            ? true
            : config('app.vpn_log_verbose', true));
    }

    public function handle(): void
    {
        Log::info('🔄 Hybrid sync: updating VPN connection status' . ($this->serverId ? " (server {$this->serverId})" : ' (fleet)'));

        /** @var Collection<int,VpnServer> $servers */
        $servers = VpnServer::query()
            ->where('deployment_status', 'succeeded')
            ->when($this->serverId, fn ($q) => $q->where('id', $this->serverId))
            ->get();

        if ($servers->isEmpty()) {
            Log::warning($this->serverId
                ? "⚠️ No VPN server found with ID {$this->serverId}"
                : "⚠️ No succeeded VPN servers found.");
            return;
        }

        foreach ($servers as $server) {
            $this->syncOneServer($server);
        }

        Log::info('✅ Hybrid sync completed');
    }

    /* ───────────────────────────────────────────────────────────── */

    protected function syncOneServer(VpnServer $server): void
    {
        try {
            Log::info("🟢 ENTERED syncOneServer for {$server->name}");

            [$raw, $source] = $this->fetchStatusWithSource($server);

            if ($raw === '') {
                Log::info("🟡 {$server->name}: RAW EMPTY, skipping");
                return;
            }

            $parsed = OpenVpnStatusParser::parse($raw);
            $usernames = collect($parsed['clients'] ?? [])->pluck('username')->filter()->values()->all();

            Log::info("APPEND_LOG: [{$server->name}] ts=" . now()->toIso8601String() .
                " source={$source} clients=" . count($usernames),
                $usernames
            );

            if ($this->verboseMgmtLog) {
                Log::info(sprintf(
                    'APPEND_LOG: [mgmt] ts=%s source=%s clients=%d [%s]',
                    now()->toIso8601String(),
                    $source,
                    count($usernames),
                    implode(',', $usernames)
                ));
            }

            // snapshot → API
            $this->pushSnapshot($server->id, now(), $usernames);

        } catch (\Throwable $e) {
            Log::error("❌ {$server->name}: sync failed – {$e->getMessage()}");
            if ($this->strictOfflineOnMissing) {
                $this->pushSnapshot($server->id, now(), []);
            }
        }
    }

    protected function fetchStatusWithSource(VpnServer $server): array
    {
        $mgmtPort = (int)($server->mgmt_port ?? 7505);

        Log::info("🔍 {$server->name}: Starting status fetch", [
            'mgmt_port' => $mgmtPort,
            'ip' => $server->ip_address,
            'ssh_user' => $server->ssh_user ?? 'root',
            'status_log_path' => $server->status_log_path ?? 'null'
        ]);

        // --- Test SSH connectivity first ---
        $testCmd = 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"');
        $sshTest = $this->executeRemoteCommand($server, $testCmd);

        if (($sshTest['status'] ?? 1) !== 0) {
            Log::error("❌ {$server->name}: SSH connectivity failed", [
                'exit_code' => $sshTest['status'] ?? 'unknown',
                'output' => $sshTest['output'] ?? []
            ]);
            return ['', 'ssh_failed'];
        }

        // try mgmt first → fallback to status files
        $mgmtCmds = [
            '{ printf "status 3\n"; sleep 1; printf "quit\n"; } | nc -w 10 127.0.0.1 ' . $mgmtPort,
            'echo -e "status 3\nquit\n" | nc -w 3 127.0.0.1 ' . $mgmtPort,
        ];

        foreach ($mgmtCmds as $cmd) {
            $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
            $out = trim(implode("\n", $res['output'] ?? []));
            if (($res['status'] ?? 1) === 0 && str_contains($out, "CLIENT_LIST")) {
                Log::info("📡 {$server->name}: mgmt responded (" . strlen($out) . " bytes)");
                return [$out, "mgmt:{$mgmtPort}"];
            }
        }

        // fallback files
        foreach (['/run/openvpn/server.status','/etc/openvpn/openvpn-status.log'] as $path) {
            $cmd = 'bash -lc ' . escapeshellarg("test -s {$path} && cat {$path} || echo '__NOFILE__'");
            $res = $this->executeRemoteCommand($server, $cmd);
            $data = trim(implode("\n", $res['output'] ?? []));
            if (($res['status'] ?? 1) === 0 && $data !== '' && $data !== '__NOFILE__') {
                Log::info("📄 {$server->name}: using status file {$path} (" . strlen($data) . " bytes)");
                return [$data, $path];
            }
        }

        Log::error("❌ {$server->name}: All methods failed - no mgmt or status file available");
        return ['', 'none'];
    }

    /**
     * Push snapshot to the API instead of direct DB/broadcast.
     */
    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $usernames): void
    {
        Log::info('🔊 pushing mgmt.update via API', [
            'server' => $serverId,
            'ts'     => $ts->format(DATE_ATOM),
            'count'  => count($usernames),
            'users'  => $usernames,
        ]);

        try {
            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->post(config('services.panel.base') . "/api/servers/{$serverId}/events", [
                    'status' => 'mgmt',
                    'ts'     => $ts->format(DATE_ATOM),
                    'users'  => array_map(fn($u) => ['username' => $u], $usernames),
                ])
                ->throw();
        } catch (\Throwable $e) {
            Log::error("❌ Failed to POST /api/servers/{$serverId}/events: {$e->getMessage()}");
        }
    }
}