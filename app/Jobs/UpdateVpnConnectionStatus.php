<?php

namespace App\Jobs;

use App\Events\ServerMgmtEvent;
use App\Models\VpnServer;
use App\Models\WireguardPeer;
use App\Services\OpenVpnStatusParser;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
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
            '🔄 Fleet sync: updating VPN connection status ' .
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
                Log::channel('vpn')->info("⏭ Skipping {$server->name} (#{$server->id}) - uses unified push agent");
                continue;
            }

            $this->syncOneServer($server);
        }

        Log::channel('vpn')->info('✅ Fleet sync completed');
    }

    /* ─────────────────────────────── */

    protected function shouldSkipForPushAgent(VpnServer $server): bool
    {
        if (property_exists($server, 'uses_unified_push') && $server->uses_unified_push) {
            return true;
        }

        // Hardcode if you must, but prefer DB flag.
        $pushServers = [
            113,
        ];

        return in_array($server->id, $pushServers, true);
    }

    protected function syncOneServer(VpnServer $server): void
    {
        try {
            // Decide path per server protocol (fallback: openvpn)
            $proto = strtolower((string) ($server->protocol ?? 'openvpn'));

            if (str_contains($proto, 'wire')) {
                $clients = $this->fetchWireguardClients($server);
            } else {
                $clients = $this->fetchOpenVpnClients($server);
            }

            broadcast(new ServerMgmtEvent(
                $server->id,
                now()->toIso8601String(),
                $clients,
                null,
                "sync:{$proto}"
            ));

            Log::channel('vpn')->debug(
                "[sync] {$server->name} proto={$proto} clients=" . count($clients),
                ['users' => array_slice(array_column($clients, 'username'), 0, 20)]
            );

            if ($this->verboseMgmtLog) {
                Log::channel('vpn')->debug(sprintf(
                    '[mgmt] ts=%s server=%d proto=%s clients=%d',
                    now()->toIso8601String(),
                    $server->id,
                    $proto,
                    count($clients)
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

    /* ===================== OPENVPN ===================== */

    protected function fetchOpenVpnClients(VpnServer $server): array
    {
        [$raw, $source] = $this->fetchOpenVpnStatusWithSource($server);

        if ($raw === '') {
            Log::channel('vpn')->warning("🟡 {$server->name}: OpenVPN RAW EMPTY, skipping");
            return [];
        }

        $parsed  = OpenVpnStatusParser::parse($raw);
        $clients = $parsed['clients'] ?? [];

        // Tag for controller normaliser (use proto key)
        $isTcp = str_contains($source, 'tcp') || str_contains($source, '7506') || str_contains($source, 'mgmt:7506');

        $mgmtPort = $isTcp ? 7506 : (int) ($server->mgmt_port ?? 7505);

        return array_map(function ($c) use ($mgmtPort, $isTcp) {
            $c['proto']     = 'openvpn';
            $c['mgmt_port'] = $mgmtPort;
            $c['protocol']  = $isTcp ? 'openvpn_tcp' : 'openvpn_udp'; // optional
            return $c;
        }, $clients);
    }

    protected function fetchOpenVpnStatusWithSource(VpnServer $server): array
    {
        $mgmtPort = (int) ($server->mgmt_port ?? 7505);

        $mgmtPorts = [$mgmtPort];
        if ($mgmtPort === 7505) $mgmtPorts[] = 7506;

        // SSH smoke test
        $sshTest = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg('echo "SSH_TEST_OK"'));
        if (($sshTest['status'] ?? 1) !== 0) {
            Log::channel('vpn')->error("❌ {$server->name}: SSH connectivity failed", [
                'exit_code' => $sshTest['status'] ?? 'unknown',
            ]);
            return ['', 'ssh_failed'];
        }

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

            if (($res['status'] ?? 1) === 0 && $data !== '' && $data !== '__NOFILE__' && str_contains($data, 'CLIENT_LIST')) {
                return [$data, $path];
            }
        }

        // mgmt fallback
        $fallbackResponse = null;

        foreach ($mgmtPorts as $port) {
            $mgmtCmds = [
                '(echo "status 3"; sleep 1; echo "quit") | nc -q 1 -w 3 127.0.0.1 ' . $port,
                '(echo "status"; sleep 1; echo "quit") | nc -q 1 -w 3 127.0.0.1 ' . $port,
            ];

            foreach ($mgmtCmds as $cmd) {
                $res = $this->executeRemoteCommand($server, 'bash -lc ' . escapeshellarg($cmd));
                $out = trim(implode("\n", $res['output'] ?? []));

                if (($res['status'] ?? 1) === 0 && str_contains($out, 'CLIENT_LIST')) {
                    $fallbackResponse = [$out, "mgmt:{$port}"];
                    if (substr_count($out, 'CLIENT_LIST') - 1 > 0) return $fallbackResponse;
                }
            }
        }

        return $fallbackResponse ?: ['', 'none'];
    }

    /* ===================== WIREGUARD ===================== */

    protected function fetchWireguardClients(VpnServer $server): array
    {
        // wg dump columns:
        // interface_pub peer_pub preshared endpoint allowed_ips latest_handshake rx tx persistent_keepalive
        $cmd = 'bash -lc ' . escapeshellarg('wg show wg0 dump 2>/dev/null || true');
        $res = $this->executeRemoteCommand($server, $cmd);

        $lines = $res['output'] ?? [];
        if (empty($lines)) {
            Log::channel('vpn')->warning("🟡 {$server->name}: WireGuard dump empty");
            return [];
        }

        // Build map peer_pub -> peer row for this server
        $peerRows = WireguardPeer::query()
            ->where('vpn_server_id', $server->id)
            ->get(['vpn_user_id', 'public_key'])
            ->keyBy('public_key');

        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;

            $parts = preg_split('/\s+/', $line);
            if (!$parts || count($parts) < 8) continue;

            $peerPub          = $parts[1] ?? null;
            $endpoint         = $parts[3] ?? null;
            $allowedIps       = $parts[4] ?? null;
            $latestHandshake  = (int) ($parts[5] ?? 0);
            $rx               = (int) ($parts[6] ?? 0);
            $tx               = (int) ($parts[7] ?? 0);

            if (!$peerPub || $peerPub === '(none)') continue;

            // only report peers we actually know in DB
            $peer = $peerRows->get($peerPub);
            if (!$peer) continue;

            $out[] = [
                // IMPORTANT: controller must understand this is WG
                'proto'        => 'wireguard',
                // IMPORTANT: give controller the public_key so it builds session_key
                'public_key'   => $peerPub,
                // keep username as pubkey too (nice for debugging)
                'username'     => $peerPub,

                'vpn_user_id'  => (int) $peer->vpn_user_id, // optional hint (controller may ignore)
                'client_ip'    => ($endpoint && $endpoint !== '(none)') ? explode(':', $endpoint, 2)[0] : null,
                'virtual_ip'   => $allowedIps ? explode('/', $allowedIps, 2)[0] : null,

                'connected_at' => $latestHandshake > 0
                    ? Carbon::createFromTimestamp($latestHandshake)->toIso8601String()
                    : null,

                'bytes_in'     => $rx,
                'bytes_out'    => $tx,
            ];
        }

        return $out;
    }

    /* ===================== PUSH ===================== */

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

            Log::channel('vpn')->debug("[pushSnapshot] #{$serverId} sent " . count($clients) . " clients");
        } catch (\Throwable $e) {
            Log::channel('vpn')->error("❌ Failed to POST /api/servers/{$serverId}/events: {$e->getMessage()}");
        }
    }
}