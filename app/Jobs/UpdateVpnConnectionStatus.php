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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UpdateVpnConnectionStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    protected ?int $serverId;
    protected bool $strictOfflineOnMissing = false;
    protected bool $verboseMgmtLog;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
        $this->verboseMgmtLog = (config('app.env') !== 'production')
            ? true
            : (bool) config('app.vpn_log_verbose', true);
    }

    public function handle(): void
    {
        Log::channel('vpn')->info(
            '🔄 OpenVPN fleet sync: updating connection status ' .
            ($this->serverId ? "(server {$this->serverId})" : '(fleet)')
        );

        /** @var Collection<int,VpnServer> $servers */
        $servers = VpnServer::query()
            ->whereIn('deployment_status', ['succeeded', 'deployed'])
            ->when($this->serverId, fn ($q) => $q->where('id', $this->serverId))
            ->get();

        if ($servers->isEmpty()) {
            Log::channel('vpn')->warning(
                $this->serverId
                    ? "⚠️ No VPN server found with ID {$this->serverId}"
                    : "⚠️ No succeeded VPN servers found."
            );
            return;
        }

        foreach ($servers as $server) {
            // ✅ DO NOT let this job touch WireGuard servers (they should be push-agent driven)
            if ($this->shouldSkipServer($server)) {
                Log::channel('vpn')->debug("⏭ Skipping {$server->name} (#{$server->id})");
                continue;
            }

            $this->syncOneOpenVpnServer($server);
        }

        Log::channel('vpn')->info('✅ OpenVPN fleet sync completed');
    }

    /* ───────────────────────────────────────────── */

    protected function shouldSkipServer(VpnServer $server): bool
    {
        // 1) If server uses unified push agent, skip
        if (property_exists($server, 'uses_unified_push') && (bool) $server->uses_unified_push) {
            return true;
        }

        // 2) If protocol indicates wireguard, skip
        $proto = strtolower((string) ($server->protocol ?? 'openvpn'));
        if (str_contains($proto, 'wire')) {
            return true;
        }

        // 3) Optional: hardcode skip list if you must
        $skipIds = [
            // 113,
        ];

        return in_array((int) $server->id, $skipIds, true);
    }

    protected function syncOneOpenVpnServer(VpnServer $server): void
    {
        try {
            [$raw, $source] = $this->fetchOpenVpnStatusWithSource($server);

            if ($raw === '') {
                Log::channel('vpn')->warning("🟡 {$server->name}: OpenVPN RAW EMPTY, skipping");
                return;
            }

            $parsed  = OpenVpnStatusParser::parse($raw);
            $clients = $parsed['clients'] ?? [];

            // Tag clients for DeployEventController normaliser
            $isTcp = str_contains($source, 'tcp')
                || str_contains($source, '7506')
                || str_contains($source, 'mgmt:7506');

            $mgmtPort = $isTcp ? 7506 : (int) ($server->mgmt_port ?? 7505);

            $clients = array_map(function ($c) use ($mgmtPort, $isTcp) {
                $c['proto']     = 'openvpn';
                $c['mgmt_port'] = $mgmtPort;
                // optional field (nice for UI/debug)
                $c['protocol']  = $isTcp ? 'openvpn_tcp' : 'openvpn_udp';
                return $c;
            }, $clients);

            // Local broadcast (reverb/live UI)
            //broadcast(new ServerMgmtEvent(
                //$server->id,
                //now()->toIso8601String(),
                //$clients,
                //null,
                //$raw
            //));

            $usernames = array_slice(array_column($clients, 'username'), 0, 20);

            Log::channel('vpn')->debug(
                "[sync] {$server->name} source={$source} clients=" . count($clients),
                ['users' => $usernames]
            );

            if ($this->verboseMgmtLog) {
                Log::channel('vpn')->debug(sprintf(
                    '[mgmt] ts=%s server=%d source=%s clients=%d',
                    now()->toIso8601String(),
                    $server->id,
                    $source,
                    count($clients)
                ));
            }

            // Push to panel API -> DeployEventController
            $this->pushSnapshot($server->id, now(), $clients);
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ {$server->name}: sync failed – {$e->getMessage()}");

            if ($this->strictOfflineOnMissing) {
                $this->pushSnapshot($server->id, now(), []);
            }
        }
    }

    /* ===================== OPENVPN STATUS FETCH ===================== */

    protected function fetchOpenVpnStatusWithSource(VpnServer $server): array
    {
        $mgmtPort = (int) ($server->mgmt_port ?? 7505);

        // Try both if default udp mgmt port used
        $mgmtPorts = [$mgmtPort];
        if ($mgmtPort === 7505) {
            $mgmtPorts[] = 7506;
        }

        // SSH smoke test
        $sshTest = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"'));
        if (($sshTest['status'] ?? 1) !== 0) {
            Log::channel('vpn')->error("❌ {$server->name}: SSH connectivity failed", [
                'exit_code' => $sshTest['status'] ?? 'unknown',
            ]);
            return ['', 'ssh_failed'];
        }

        // Prefer status files
        $statusFiles = [
            '/var/log/openvpn-status-udp.log',
            '/var/log/openvpn-status-tcp.log',
            '/run/openvpn/server.status',
            '/run/openvpn/server-tcp.status',
            '/etc/openvpn/openvpn-status.log',
        ];

        foreach ($statusFiles as $path) {
            $cmd  = 'bash -lc ' . escapeshellarg("test -s {$path} && cat {$path} || echo '__NOFILE__'");
            $res  = $this->executeRemoteCommand($server, $cmd);
            $data = trim(implode("\n", $res['output'] ?? []));

            if (
                ($res['status'] ?? 1) === 0 &&
                $data !== '' &&
                $data !== '__NOFILE__' &&
                str_contains($data, 'CLIENT_LIST')
            ) {
                return [$data, $path];
            }
        }

        // mgmt fallback
        $fallback = null;

        foreach ($mgmtPorts as $port) {
            $mgmtCmds = [
                '(echo "status 3"; sleep 1; echo "quit") | nc -q 1 -w 3 127.0.0.1 ' . $port,
                '(echo "status"; sleep 1; echo "quit") | nc -q 1 -w 3 127.0.0.1 ' . $port,
            ];

            foreach ($mgmtCmds as $cmd) {
                $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
                $out = trim(implode("\n", $res['output'] ?? []));

                if (($res['status'] ?? 1) === 0 && str_contains($out, 'CLIENT_LIST')) {
                    $fallback = [$out, "mgmt:{$port}"];

                    // if it has real clients, return immediately
                    $clientCount = max(0, (substr_count($out, 'CLIENT_LIST') - 1));
                    if ($clientCount > 0) {
                        return $fallback;
                    }
                }
            }
        }

        return $fallback ?: ['', 'none'];
    }

    /* ===================== PUSH ===================== */

    protected function pushSnapshot(int $serverId, \DateTimeInterface $ts, array $clients): void
{
    // CRITICAL: never send an authoritative mgmt snapshot with 0 clients.
    // That will wipe the dashboard (authoritative replace).
    if (count($clients) === 0) {
        Log::channel('vpn')->debug("[pushSnapshot] #{$serverId} clients=0 -> SKIP (prevent wipe)");
        return;
    }

    try {
        Http::withToken(config('services.panel.token'))
            ->acceptJson()
            ->post(config('services.panel.base') . "/api/servers/{$serverId}/events", [
                'status' => 'mgmt',
                'ts'     => $ts->format(DATE_ATOM),
                'users'  => $clients,
            ])
            ->throw();

        Log::channel('vpn')->debug("[pushSnapshot] #{$serverId} sent " . count($clients) . " clients");
    } catch (\Throwable $e) {
        Log::channel('vpn')->error("❌ Failed to POST /api/servers/{$serverId}/events: {$e->getMessage()}");
    }
}
}