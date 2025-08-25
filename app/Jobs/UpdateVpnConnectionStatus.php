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

    protected function syncOneServer(VpnServer $server): void
    {
        try {
            $raw = $this->fetchOpenVpnStatusLog($server);

            if ($raw === '') {
                Log::warning("⚠️ {$server->name}: status not readable from file or mgmt.");
                if ($this->strictOfflineOnMissing) {
                    $this->markAllUsersDisconnected($server);
                    $this->broadcastSnapshot($server->id, now(), []);
                }
                return;
            }

            $parsed = OpenVpnStatusParser::parse($raw);
            $connected = [];

            foreach ($parsed['clients'] as $c) {
                $username = (string)($c['username'] ?? '');
                if ($username === '') continue;

                $connected[$username] = [
                    'client_ip'      => $c['client_ip'] ?? null,
                    'bytes_received' => (int)($c['bytes_received'] ?? 0),
                    'bytes_sent'     => (int)($c['bytes_sent'] ?? 0),
                    'connected_at'   => isset($c['connected_at'])
                        ? Carbon::createFromTimestamp($c['connected_at'])
                        : null,
                ];
            }

            $this->upsertConnections($server, $connected);

            // Optional columns (ignore if not present)
            try {
                $server->forceFill([
                    'online_users' => count($connected),
                    'last_sync_at' => now(),
                ])->saveQuietly();
            } catch (\Throwable) {}

            $this->broadcastSnapshot($server->id, now(), array_keys($connected));

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
            Log::error("❌ fetchOpenVpnStatusLog: Server not found for IP: {$server}");
            return '';
        }
    }

    // 1) Try common status-file paths first
    $candidates = array_values(array_unique(array_filter([
        $server->status_log_path ?? null,
        '/run/openvpn/server.status',
        '/run/openvpn/openvpn.status',
        '/run/openvpn/server/server.status',
        '/var/log/openvpn-status.log',
    ])));

    foreach ($candidates as $path) {
        $cmd = 'bash -lc ' . escapeshellarg(
            'test -s ' . escapeshellarg($path) . ' && cat ' . escapeshellarg($path) . ' || echo "__NOFILE__"'
        );
        $res = $this->executeRemoteCommand($server, $cmd);
        if (($res['status'] ?? 1) === 0) {
            $out = trim(implode("\n", $res['output'] ?? []));
            if ($out !== '' && $out !== '__NOFILE__') {
                Log::info("📄 {$server->name}: using {$path}");
                return $out;
            } else {
                Log::warning("⚠️ {$server->name}: {$path} not found or empty");
            }
        } else {
            Log::warning("⚠️ {$server->name}: SSH error when reading {$path}");
        }
    }

    // 2) Fallback to mgmt socket (the command you tested manually)
    $mgmtPort = (int)($server->mgmt_port ?? 7505);
    $mgmtCmd = 'bash -lc ' . escapeshellarg(
        '{ printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } '
      . '| nc -w 2 127.0.0.1 ' . $mgmtPort . ' 2>/dev/null'
    );

    $res = $this->executeRemoteCommand($server, $mgmtCmd);
    if (($res['status'] ?? 1) === 0) {
        $out = trim(implode("\n", $res['output'] ?? []));
        if ($out !== '' && str_contains($out, 'OpenVPN Management Interface')) {
            Log::info("📡 {$server->name}: read status via mgmt :{$mgmtPort}");
            return $out;
        }
    }
    Log::warning("⚠️ {$server->name}: mgmt socket read failed");
    Log::warning("⚠️ {$server->name}: status not readable from file or mgmt.");
    return '';
}

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