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
     * Should we mark everyone offline if status file/mgmt is missing?
     */
    protected bool $strictOfflineOnMissing = false;

    public function __construct(?int $serverId = null)
    {
        $this->serverId = $serverId;
    }

    public function handle(): void
    {
        Log::info('🔄 Hybrid sync: updating VPN connection status'
            . ($this->serverId ? " (server {$this->serverId})" : ' (fleet)'));

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

    /**
 * Sync one OpenVPN server: read status (file or mgmt), persist connections,
 * update server counters, and broadcast a rich snapshot for the UI.
 */
protected function syncOneServer(VpnServer $server): void
{
    try {
        $raw = $this->fetchOpenVpnStatusLog($server);

        if ($raw === '') {
            Log::warning("⚠️ {$server->name}: status not readable from file or mgmt.");
            if ($this->strictOfflineOnMissing) {
                $this->markAllUsersDisconnected($server);
                // Broadcast “empty” to clear UI
                $this->broadcastSnapshot($server->id, now(), []);
            }
            return;
        }

        $parsed    = OpenVpnStatusParser::parse($raw); // v3-aware
        $connected = [];  // keyed by username for DB upsert
        $usersForBroadcast = []; // rich rows for the UI

        foreach ($parsed['clients'] as $c) {
            $username = (string)($c['username'] ?? '');
            if ($username === '') {
                continue;
            }

            // Normalize & cast
            $clientIp    = $c['client_ip']   ?? null;
            $virtualIp   = $c['virtual_ip']  ?? null;
            $bytesRecv   = (int)($c['bytes_received'] ?? 0); // client -> server
            $bytesSent   = (int)($c['bytes_sent']     ?? 0); // server -> client
            $connectedAt = isset($c['connected_at']) && is_numeric($c['connected_at'])
                ? Carbon::createFromTimestamp((int)$c['connected_at'])
                : null;

            // For DB layer (your VpnUserConnection columns)
            $connected[$username] = [
                'client_ip'      => $clientIp,
                'virtual_ip'     => $virtualIp,
                'bytes_received' => $bytesRecv,
                'bytes_sent'     => $bytesSent,
                'connected_at'   => $connectedAt,
            ];

            // For live UI
            $usersForBroadcast[] = [
                'username'        => $username,
                'client_ip'       => $clientIp,
                'virtual_ip'      => $virtualIp,
                'bytes_in'        => $bytesRecv, // ↓ in UI (from client)
                'bytes_out'       => $bytesSent, // ↑ in UI (to client)
                'connected_at'    => $connectedAt?->timestamp,
                'connected_human' => $connectedAt?->diffForHumans() ?? null,
                'connected_fmt'   => $connectedAt?->toIso8601String() ?? null,
                'formatted_bytes' => null, // let the front-end format totals if desired
                'down_mb'         => $bytesRecv > 0 ? round($bytesRecv / 1048576, 2) : 0.0,
                'up_mb'           => $bytesSent > 0 ? round($bytesSent / 1048576, 2) : 0.0,
            ];
        }

        // Persist
        $this->upsertConnections($server, $connected);

        // Optional server counters (ignore if columns don’t exist)
        try {
            $server->forceFill([
                'online_users' => count($connected),
                'last_sync_at' => now(),
            ])->saveQuietly();
        } catch (\Throwable) {
            // columns optional — ignore
        }

        // Broadcast rich snapshot (preferred). If your event doesn’t yet include `users`,
        // keep your existing broadcastSnapshot() as a fallback.
        try {
            // If you've updated ServerMgmtEvent to accept `users`, do this:
            broadcast(new \App\Events\ServerMgmtEvent(
                serverId: $server->id,
                ts: now()->toAtomString(),
                clients: count($usersForBroadcast),
                cnList: implode(',', array_keys($connected)),
                raw: 'sync-job',
                users: $usersForBroadcast,  // <-- requires the extended event I shared earlier
            ));
        } catch (\Throwable $e) {
            // Fallback to legacy “names-only” broadcast
            $this->broadcastSnapshot($server->id, now(), array_keys($connected));
        }

    } catch (\Throwable $e) {
        Log::error("❌ {$server->name}: sync failed – {$e->getMessage()}");
        if ($this->strictOfflineOnMissing) {
            $this->markAllUsersDisconnected($server);
            $this->broadcastSnapshot($server->id, now(), []);
        }
    }
}

    /**
     * Try file (v3 path), then fall back to mgmt socket `status 3`.
     */
    protected function fetchOpenVpnStatusLog(VpnServer|string $server): string
{
    if (is_string($server)) {
        $server = \App\Models\VpnServer::where('ip_address', $server)->first();
        if (!$server) {
            Log::error("❌ fetchOpenVpnStatusLog: Server not found for IP: $server");
            return '';
        }
    }

    $mgmtPort = (int)($server->mgmt_port ?? 7505);

    // === 1) Try Management Socket First ===
    $mgmtCmd = 'bash -lc ' . escapeshellarg(
        'set -o pipefail; { printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } | nc -w 2 127.0.0.1 '.$mgmtPort
    );

    $res = $this->executeRemoteCommand($server, $mgmtCmd);
    $out = trim(implode("\n", $res['output'] ?? []));

    if (($res['status'] ?? 1) === 0 && $out !== '' &&
        (str_contains($out, 'OpenVPN Management Interface') || str_contains($out, "\tCLIENT_LIST\t"))) {
        Log::info("📡 {$server->name}: read status via mgmt :{$mgmtPort}");
        return $out;
    }

    // === 2) Fall Back to Status File Locations ===
    $candidates = array_values(array_unique(array_filter([
        $server->status_log_path ?? null,
        '/run/openvpn/server.status',
        '/run/openvpn/openvpn.status',
        '/run/openvpn/server/server.status',
        '/var/log/openvpn-status.log',
    ])));

    foreach ($candidates as $path) {
        $cmd = 'bash -lc ' . escapeshellarg(
            'test -s '.escapeshellarg($path).' && cat '.escapeshellarg($path).' || echo "__NOFILE__"'
        );
        $res = $this->executeRemoteCommand($server, $cmd);

        if (($res['status'] ?? 1) === 0) {
            $data = trim(implode("\n", $res['output'] ?? []));
            if ($data !== '' && $data !== '__NOFILE__') {
                Log::info("📄 {$server->name}: using {$path}");
                return $data;
            }
        }
    }

    // === 3) Nothing worked ===
    Log::warning("⚠️ {$server->name}: status not readable from mgmt or file.");
    return '';
}

// 2) FALL BACK TO FILE LOCATIONS (only if mgmt didn’t work)
$candidates = array_values(array_unique(array_filter([
    $server->status_log_path ?? null,
    '/run/openvpn/server.status',
    '/run/openvpn/openvpn.status',
    '/run/openvpn/server/server.status',
    '/var/log/openvpn-status.log',
])));

foreach ($candidates as $path) {
    $cmd = 'bash -lc ' . escapeshellarg('test -s '.escapeshellarg($path).' && cat '.escapeshellarg($path).' || echo "__NOFILE__"');
    $res = $this->executeRemoteCommand($server, $cmd);
    if (($res['status'] ?? 1) === 0) {
        $data = trim(implode("\n", $res['output'] ?? []));
        if ($data !== '' && $data !== '__NOFILE__') {
            Log::info("📄 {$server->name}: using {$path}");
            return $data;
        }
    }
}

Log::warning("⚠️ {$server->name}: status not readable from mgmt or file.");
return '';
    protected function upsertConnections(VpnServer $server, array $connected): void
    {
        DB::transaction(function () use ($server, $connected) {
            $connectedUsernames = array_keys($connected);
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