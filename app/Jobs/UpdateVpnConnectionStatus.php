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
            : (env('VPN_LOG_VERBOSE', true)));
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
            [$raw, $source] = $this->fetchStatusWithSource($server);

            if ($raw === '') {
                Log::warning("⚠️ {$server->name}: status not readable from file or mgmt.");
                if ($this->strictOfflineOnMissing) {
                    $this->pushSnapshot($server->id, now(), []);
                }
                return;
            }

            $parsed = OpenVpnStatusParser::parse($raw);

            // Collect connected usernames
            $usernames = [];
            foreach ($parsed['clients'] as $c) {
                $username = (string)($c['username'] ?? '');
                if ($username !== '') $usernames[] = $username;
            }

            if ($this->verboseMgmtLog) {
                Log::info(sprintf(
                    'APPEND_LOG: [mgmt] ts=%s source=%s clients=%d [%s]',
                    now()->toIso8601String(),
                    $source,
                    count($usernames),
                    implode(',', $usernames)
                ));
            }

            // Push snapshot to API (DB + Echo handled there)
            $this->pushSnapshot($server->id, now(), $usernames);
            Log::info("📤 pushSnapshot →", ['server' => $serverId, 'users' => $usernames]);

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

    // 1) Management socket
    $mgmtCmd = 'bash -lc ' . escapeshellarg(
        'set -o pipefail; { printf "status 3\r\n"; sleep 0.5; printf "quit\r\n"; } | nc -w 3 127.0.0.1 ' . $mgmtPort
        //                                                               ^ space fixed!
    );

    $res = $this->executeRemoteCommand($server, $mgmtCmd);
    $out = trim(implode("\n", $res['output'] ?? []));

    if (($res['status'] ?? 1) === 0 && $out !== '' &&
        (str_contains($out, "CLIENT_LIST") || str_contains($out, "OpenVPN Management Interface"))) {
        Log::info("📡 {$server->name}: mgmt responded with " . strlen($out) . " bytes");
        return [$out, "mgmt:{$mgmtPort}"];
    }

    // 2) Fallback: check known status files
    $candidates = array_filter([
        $server->status_log_path ?? null,
        '/run/openvpn/server.status',
        '/run/openvpn/openvpn.status',
        '/run/openvpn/server/server.status',
        '/var/log/openvpn-status.log',
    ]);

    foreach ($candidates as $path) {
        $cmd = 'bash -lc ' . escapeshellarg(
            "test -s {$path} && cat {$path} || echo '__NOFILE__'"
        );
        $res = $this->executeRemoteCommand($server, $cmd);
        $data = trim(implode("\n", $res['output'] ?? []));
        if (($res['status'] ?? 1) === 0 && $data !== '' && $data !== '__NOFILE__') {
            Log::info("📄 {$server->name}: using {$path} (" . strlen($data) . " bytes)");
            return [$data, $path];
        }
    }

    Log::warning("⚠️ {$server->name}: no mgmt or status file available");
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