<?php

namespace App\Jobs;

use App\Events\ServerMgmtEvent;
use App\Models\VpnServer;
use App\Models\VpnUserConnection;
use App\Services\OpenVpnStatusParser;
use App\Traits\ExecutesRemoteCommands;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateVpnConnectionStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ExecutesRemoteCommands;

    /**
     * Optional server ID to restrict the sync to.
     */
    protected ?int $serverId;

    /**
     * If true: when status cannot be read, mark everyone offline for that server.
     */
    protected bool $strictOfflineOnMissing = false;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
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

    /* -------------------------------------------------------------------- */

    /**
     * Read status (mgmt->file), persist connections, update counters, broadcast snapshot.
     */
    protected function syncOneServer(VpnServer $server): void
    {
        try {
            $raw = $this->fetchOpenVpnStatusLog($server);

            if ($raw === '') {
                Log::warning("⚠️ {$server->name}: status not readable from mgmt or file.");
                if ($this->strictOfflineOnMissing) {
                    $this->markAllUsersDisconnected($server);
                    $this->broadcastSnapshot($server->id, now(), []);
                }
                return;
            }

            $parsed = OpenVpnStatusParser::parse($raw);

            // Build a map keyed by username for DB persistence.
            $connected = []; // username => payload
            foreach ($parsed['clients'] as $c) {
                $username = (string)($c['username'] ?? '');
                if ($username === '') {
                    continue;
                }

                $clientIp    = $c['client_ip']   ?? null;
                $virtualIp   = $c['virtual_ip']  ?? null;
                $bytesRecv   = (int)($c['bytes_received'] ?? 0); // client -> server
                $bytesSent   = (int)($c['bytes_sent']     ?? 0); // server -> client
                $connectedAt = null;

                if (isset($c['connected_at']) && is_numeric($c['connected_at'])) {
                    $connectedAt = Carbon::createFromTimestamp((int)$c['connected_at']);
                }

                $connected[$username] = [
                    'client_ip'      => $clientIp,
                    'virtual_ip'     => $virtualIp,
                    'bytes_received' => $bytesRecv,
                    'bytes_sent'     => $bytesSent,
                    'connected_at'   => $connectedAt,
                ];
            }

            // Persist rows
            $this->upsertConnections($server, $connected);

            // Optional counters on vpn_servers (ignore if columns absent)
            try {
                $server->forceFill([
                    'online_users' => count($connected),
                    'last_sync_at' => now(),
                ])->saveQuietly();
            } catch (\Throwable) {
                // columns optional — ignore
            }

            // Broadcast legacy snapshot (count + CSV), matching your current ServerMgmtEvent signature
            $this->broadcastSnapshot(
                serverId: $server->id,
                ts: now(),
                usernames: array_keys($connected)
            );

        } catch (\Throwable $e) {
            Log::error("❌ {$server->name}: sync failed – {$e->getMessage()}");
            if ($this->strictOfflineOnMissing) {
                $this->markAllUsersDisconnected($server);
                $this->broadcastSnapshot($server->id, now(), []);
            }
        }
    }

    /**
     * Try management socket first; if it fails, try common file paths.
     */
    protected function fetchOpenVpnStatusLog(VpnServer $server): string
    {
        // === 1) Management socket (preferred) ===
        $mgmtPort = (int)($server->mgmt_port ?? 7505);

        $mgmtCmd = 'bash -lc ' . escapeshellarg(
            'set -o pipefail; { printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } | nc -w 2 127.0.0.1 ' . $mgmtPort . ' 2>/dev/null || true'
        );

        $res = $this->executeRemoteCommand($server, $mgmtCmd);
        $out = trim(implode("\n", $res['output'] ?? []));

        if (($res['status'] ?? 1) === 0 && $out !== '') {
            // quick sanity check that this looks like an OpenVPN status v3 block
            if (str_contains($out, "OpenVPN Management Interface") || str_contains($out, "\tCLIENT_LIST\t")) {
                Log::info("📡 {$server->name}: read status via mgmt :{$mgmtPort}");
                return $out;
            }
        }

        // === 2) File fallback (try several common locations) ===
        $candidates = array_values(array_unique(array_filter([
            $server->status_log_path ?? null,     // per-server override, if set
            '/run/openvpn/server.status',         // systemd template default (v3)
            '/run/openvpn/openvpn.status',
            '/run/openvpn/server/server.status',
            '/var/log/openvpn-status.log',        // classic v2
        ], fn ($p) => is_string($p) && $p !== '')));

        foreach ($candidates as $path) {
            $cmd = 'bash -lc ' . escapeshellarg(
                'test -s ' . escapeshellarg($path) . ' && cat ' . escapeshellarg($path) . ' || echo "__NOFILE__"'
            );
            $res = $this->executeRemoteCommand($server, $cmd);

            if (($res['status'] ?? 1) !== 0) {
                continue;
            }

            $data = trim(implode("\n", $res['output'] ?? []));
            if ($data !== '' && $data !== '__NOFILE__') {
                Log::info("📄 {$server->name}: using {$path}");
                return $data;
            }
        }

        return '';
    }

    /**
     * Create/update per-user connection rows for this server.
     *
     * @param array<string,array{
     *   client_ip:?string, virtual_ip:?string, bytes_received:int, bytes_sent:int, connected_at:?Carbon
     * }> $connected
     */
    protected function upsertConnections(VpnServer $server, array $connected): void
    {
        DB::transaction(function () use ($server, $connected) {
            $connectedUsernames = array_keys($connected);

            // Only users that belong to this server (your pivot)
            $serverUsers = $server->vpnUsers()->get(['vpn_users.id', 'vpn_users.username']);

            foreach ($serverUsers as $user) {
                $row = VpnUserConnection::firstOrCreate([
                    'vpn_user_id'   => $user->id,
                    'vpn_server_id' => $server->id,
                ]);

                if (in_array($user->username, $connectedUsernames, true)) {
                    $u = $connected[$user->username];

                    if (!$row->is_connected) {
                        $row->connected_at    = $u['connected_at'] ?? now();
                        $row->disconnected_at = null;
                    }

                    $row->is_connected   = true;
                    $row->client_ip      = $u['client_ip']      ?? $row->client_ip;
                    $row->virtual_ip     = $u['virtual_ip']     ?? $row->virtual_ip;
                    $row->bytes_received = $u['bytes_received'] ?? $row->bytes_received;
                    $row->bytes_sent     = $u['bytes_sent']     ?? $row->bytes_sent;
                    $row->save();

                    $user->forceFill([
                        'is_online' => true,
                        'last_ip'   => $row->client_ip,
                    ])->save();
                } else {
                    if ($row->is_connected) {
                        $row->is_connected    = false;
                        $row->disconnected_at = now();
                        $row->save();
                    }

                    VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($user->id);
                }
            }
        });
    }

    /**
     * Mark everyone on this server as disconnected (when we cannot read status).
     */
    protected function markAllUsersDisconnected(VpnServer $server): void
    {
        $conns = VpnUserConnection::where('vpn_server_id', $server->id)
            ->where('is_connected', true)
            ->get();

        foreach ($conns as $row) {
            $row->update([
                'is_connected'    => false,
                'disconnected_at' => now(),
            ]);

            VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
        }
    }

    /**
     * Broadcast the legacy “names-only” snapshot that your current ServerMgmtEvent expects.
     */
    protected function broadcastSnapshot(int $serverId, \DateTimeInterface $ts, array $usernames): void
    {
        broadcast(new ServerMgmtEvent(
            $serverId,
            $ts->format(DATE_ATOM),
            count($usernames),
            implode(',', $usernames),
            'sync-job'
        ));
    }
}