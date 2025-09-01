<?php

namespace App\Jobs;

use App\Events\ServerMgmtEvent;
use App\Models\VpnServer;
use App\Services\OpenVpnStatusParser;
use App\Traits\ExecutesRemoteCommands;
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
        $this->verboseMgmtLog = config('app.env') !== 'production'
            ? true
            : (bool) config('app.vpn_log_verbose', false);
    }

    public function handle(): void
    {
        Log::info('🔄 VPN sync started' . ($this->serverId ? " (server {$this->serverId})" : ' (fleet)'));

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

        Log::info('✅ VPN sync completed');
    }

    /* ───────────────────────────────────────────────────────────── */

    protected function syncOneServer(VpnServer $server): void
    {
        try {
            [$raw, $source] = $this->fetchStatusWithSource($server);

            if ($raw === '') {
                Log::warning("🟡 {$server->name}: no status output, skipping");
                return;
            }

            $parsed  = OpenVpnStatusParser::parse($raw);
            $clients = $parsed['clients'] ?? [];
            $usernames = array_column($clients, 'username');

            // Broadcast
            broadcast(new ServerMgmtEvent(
                $server->id,
                now()->toIso8601String(),
                $clients,
                null,
                $raw
            ));

            // Throttled logging (state change or every 60s)
            $stateKey   = "server:{$server->id}:last_state";
            $logKey     = "server:{$server->id}:last_log";
            $lastState  = cache()->get($stateKey);
            $state      = count($clients) . '|' . implode(',', $usernames);

            $shouldLog = false;
            if ($state !== $lastState) {
                $shouldLog = true;
                cache()->put($stateKey, $state, 300);
            } elseif (!cache()->has($logKey)) {
                $shouldLog = true;
            }

            if ($shouldLog) {
                Log::channel('vpn')->info("📊 {$server->name}: " . count($clients) . " clients", $usernames);
                cache()->put($logKey, 1, 60);
            }

            if ($this->verboseMgmtLog) {
                Log::channel('vpn')->debug("{$server->name}: mgmt source={$source}, raw_length=" . strlen($raw));
            }

            // Push snapshot → API
            $this->pushSnapshot($server->id, now(), $clients);

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

        Log::debug("{$server->name}: fetching status via mgmt port {$mgmtPort}");

        // --- Test SSH connectivity
        $testCmd = 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"');
        $sshTest = $this->executeRemoteCommand($server, $testCmd);
        if (($sshTest['status'] ?? 1) !== 0) {
            Log::error("❌ {$server->name}: SSH connectivity failed");
            return ['', 'ssh_failed'];
        }

        // try mgmt
        $mgmtCmds = [
            '{ printf "status 3\n"; sleep 1; printf "quit\n"; } | nc -w 10 127.0.0.1 ' . $mgmtPort,
            'echo -e "status 3\nquit\n" | nc -w 3 127.0.0.1 ' . $mgmtPort,
        ];

        foreach ($mgmtCmds as $cmd) {
            $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
            $out = trim(implode("\n", $res['output'] ?? []));
            if (($res['status'] ?? 1) === 0 && str_contains($out, "CLIENT_LIST")) {
                Log::debug("{$server->name}: mgmt responded");
                return [$out, "mgmt:{$mgmtPort}"];
            }
        }

        // fallback to status files
        foreach (['/run/openvpn/server.status','/etc/openvpn/openvpn-status.log'] as $path) {
            $cmd = 'bash -lc ' . escapeshellarg("test -s {$path} && cat {$path} || echo '__NOFILE__'");
            $res = $this->executeRemoteCommand($server, $cmd);
            $data = trim(implode("\n", $res['output'] ?? []));
            if (($res['status'] ?? 1) === 0 && $data !== '' && $data !== '__NOFILE__') {
                Log::debug("{$server->name}: using status file {$path}");
                return [$data, $path];
            }
        }

        Log::error("❌ {$server->name}: no mgmt or status file available");
        return ['', 'none'];
    }

    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $clients): void
    {
        try {
            Http::withToken(config('services.panel.token'))
                ->acceptJson()
                ->post(config('services.panel.base') . "/api/servers/{$serverId}/events", [
                    'status' => 'mgmt',
                    'ts'     => $ts->format(DATE_ATOM),
                    'users'  => $clients,
                ])
                ->throw();

        } catch (\Throwable $e) {
            Log::error("❌ Failed to POST /api/servers/{$serverId}/events: {$e->getMessage()}");
        }
    }
}