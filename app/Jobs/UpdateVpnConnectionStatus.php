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
            '🔄 Hybrid sync: updating VPN connection status ' .
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
            if ($this->shouldSkipForPushAgent($server)) {
                Log::channel('vpn')->info(
                    "⏭ Skipping hybrid sync for {$server->name} (#{$server->id}) - uses unified push agent"
                );
                continue;
            }

            $this->syncOneServer($server);
        }

        Log::channel('vpn')->info('✅ Hybrid sync completed');
    }

    /* ─────────────────────────────────────────────── */

    protected function shouldSkipForPushAgent(VpnServer $server): bool
    {
        // Preferred: enable per-server flag in DB when using ovpn-mgmt-push.sh
        if (property_exists($server, 'uses_unified_push') && $server->uses_unified_push) {
            return true;
        }

        // Fallback: hardcode push-enabled servers here
        $pushServers = [
            113, // Spain WG+OVPN node using /usr/local/bin/ovpn-mgmt-push.sh
        ];

        return in_array($server->id, $pushServers, true);
    }

    protected function syncOneServer(VpnServer $server): void
    {
        try {
            [$raw, $source] = $this->fetchStatusWithSource($server);

            if ($raw === '') {
                Log::channel('vpn')->warning("🟡 {$server->name}: RAW EMPTY, skipping");
                return;
            }

            $parsed  = OpenVpnStatusParser::parse($raw);
            $clients = $parsed['clients'] ?? [];

            broadcast(new ServerMgmtEvent(
                $server->id,
                now()->toIso8601String(),
                $clients,
                null,
                $raw
            ));

            $usernames = array_column($clients, 'username');

            Log::channel('vpn')->debug(
                "[sync] {$server->name} source={$source} clients=" . count($clients),
                ['users' => $usernames]
            );

            if ($this->verboseMgmtLog) {
                Log::channel('vpn')->debug(sprintf(
                    '[mgmt] ts=%s source=%s clients=%d [%s]',
                    now()->toIso8601String(),
                    $source,
                    count($clients),
                    implode(',', $usernames)
                ));
            }

            $this->pushSnapshot($server->id, now(), $clients);
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ {$server->name}: sync failed – {$e->getMessage()}");

            if ($this->strictOfflineOnMissing) {
                $this->pushSnapshot($server->id, now(), []);
            }
        }
    }

    protected function fetchStatusWithSource(VpnServer $server): array
    {
        $mgmtPort = (int) ($server->mgmt_port ?? 7505);

        // Check both UDP (7505) and TCP (7506) management interfaces
        $mgmtPorts = [$mgmtPort];
        if ($mgmtPort === 7505) {
            $mgmtPorts[] = 7506; // TCP stealth mgmt
        }

        Log::channel('vpn')->debug("🔍 {$server->name}: Starting status fetch", [
            'mgmt_ports' => $mgmtPorts,
            'ip'         => $server->ip_address,
            'ssh_user'   => $server->ssh_user ?? 'root',
        ]);

        // SSH connectivity test
        $testCmd = 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"');
        $sshTest = $this->executeRemoteCommand($server, $testCmd);

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

        Log::channel('vpn')->debug("🔍 {$server->name}: Checking " . count($statusFiles) . " status files...");

        foreach ($statusFiles as $path) {
            $cmd = 'bash -lc ' . escapeshellarg("test -s {$path} && cat {$path} || echo '__NOFILE__'");
            $res = $this->executeRemoteCommand($server, $cmd);
            $data = trim(implode("\n", $res['output'] ?? []));

            Log::channel('vpn')->debug(
                "  ├─ {$path}: status={$res['status']}, data_len=" . strlen($data) .
                ", has_CLIENT_LIST=" . (str_contains($data, 'CLIENT_LIST') ? 'YES' : 'NO')
            );

            if (
                ($res['status'] ?? 1) === 0 &&
                $data !== '' &&
                $data !== '__NOFILE__' &&
                str_contains($data, 'CLIENT_LIST')
            ) {
                Log::channel('vpn')->info(
                    "📄 {$server->name}: using status file {$path} (" . strlen($data) . " bytes)"
                );
                return [$data, $path];
            }
        }

        Log::channel('vpn')->warning(
            "⚠️ {$server->name}: No valid status file found, falling back to mgmt interface"
        );

        // Fallback to mgmt ports
        $fallbackResponse = null;

        foreach ($mgmtPorts as $port) {
            $mgmtCmds = [
                '(echo "status 3"; sleep 1; echo "quit") | nc -q 1 -w 3 127.0.0.1 ' . $port,
                '(echo "status"; sleep 1; echo "quit") | nc -q 1 -w 3 127.0.0.1 ' . $port,
            ];

            for ($i = 0; $i < count($mgmtCmds); $i++) {
                $cmd = $mgmtCmds[$i];
                $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
                $out = trim(implode("\n", $res['output'] ?? []));

                if (($res['status'] ?? 1) === 0 && str_contains($out, 'CLIENT_LIST')) {
                    $clientCount = substr_count($out, 'CLIENT_LIST') - 1;

                    Log::channel('vpn')->debug("📡 {$server->name}: mgmt responded on port {$port} ({$clientCount} clients, " . strlen($out) . " bytes)", [
                        'preview' => substr($out, 0, 200) . '...',
                    ]);

                    if ($clientCount > 0) {
                        return [$out, "mgmt:{$port}"];
                    }

                    $fallbackResponse = [$out, "mgmt:{$port}"];
                }
            }
        }

        if ($fallbackResponse) {
            return $fallbackResponse;
        }

        Log::channel('vpn')->warning("⚠️ {$server->name}: No status data found - all methods failed");

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

            Log::channel('vpn')->debug(
                "[pushSnapshot] #{$serverId} sent " . count($clients) . " clients"
            );
        } catch (\Throwable $e) {
            Log::channel('vpn')->error(
                "❌ Failed to POST /api/servers/{$serverId}/events: {$e->getMessage()}"
            );
        }
    }
}