<?php

namespace App\Jobs;

use App\Events\ServerMgmtEvent;
use App\Models\VpnServer;
use App\Models\VpnUser;
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

    protected ?int $serverId;
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
            ->when($this->serverId, fn($q) => $q->where('id', $this->serverId))
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
                    $this->disconnectAllOnServer($server->id);
                    $this->broadcastSnapshot($server->id, now(), []);
                }
                return;
            }

            $parsed = OpenVpnStatusParser::parse($raw);

            // Build “currently connected” map keyed by username
            $connectedByUsername = [];
            foreach ($parsed['clients'] as $c) {
                $username = (string)($c['username'] ?? '');
                if ($username === '') continue;

                $connectedByUsername[$username] = [
                    'client_ip'      => $c['client_ip']      ?? null,
                    'virtual_ip'     => $c['virtual_ip']     ?? null,
                    'bytes_received' => (int)($c['bytes_received'] ?? 0), // client->server
                    'bytes_sent'     => (int)($c['bytes_sent']     ?? 0), // server->client
                    'connected_at'   => isset($c['connected_at']) && is_numeric($c['connected_at'])
                        ? Carbon::createFromTimestamp((int) $c['connected_at'])
                        : null,
                ];
            }

            // Persist everything in a single transaction, **without** relying on the pivot.
            $updatedUserIds = $this->upsertConnectionsByUsername($server->id, $connectedByUsername);

            // Optional counters (ignore if columns absent)
            try {
                $server->forceFill([
                    'online_users' => count($updatedUserIds),
                    'last_sync_at' => now(),
                ])->saveQuietly();
            } catch (\Throwable) {}

            // Broadcast names-only like before (keeps Alpine code unchanged)
            $this->broadcastSnapshot($server->id, now(), array_keys($connectedByUsername));

        } catch (\Throwable $e) {
            Log::error("❌ {$server->name}: sync failed – {$e->getMessage()}");
            if ($this->strictOfflineOnMissing) {
                $this->disconnectAllOnServer($server->id);
                $this->broadcastSnapshot($server->id, now(), []);
            }
        }
    }

    /**
     * Prefer management socket; fall back to common status-file paths.
     */
    protected function fetchOpenVpnStatusLog(VpnServer|string $server): string
    {
        if (is_string($server)) {
            $server = VpnServer::where('ip_address', $server)->first();
            if (!$server) {
                Log::error("❌ fetchOpenVpnStatusLog: server not found for IP: {$server}");
                return '';
            }
        }

        $mgmtPort = (int)($server->mgmt_port ?? 7505);

        // 1) Management socket
        $mgmtCmd = 'bash -lc ' . escapeshellarg(
            'set -o pipefail; { printf "status 3\r\n"; sleep 0.3; printf "quit\r\n"; } | nc -w 2 127.0.0.1 ' . $mgmtPort
        );
        $res = $this->executeRemoteCommand($server, $mgmtCmd);
        $out = trim(implode("\n", $res['output'] ?? []));
        if (($res['status'] ?? 1) === 0 && $out !== '' &&
            (str_contains($out, "\tCLIENT_LIST\t") || str_contains($out, 'OpenVPN Management Interface'))) {
            Log::info("📡 {$server->name}: read status via mgmt :{$mgmtPort}");
            return $out;
        }

        // 2) File paths (v3 first, then v2)
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
            if (($res['status'] ?? 1) !== 0) {
                Log::warning("⚠️ {$server->name}: {$path} not found or empty");
                continue;
            }
            $data = trim(implode("\n", $res['output'] ?? []));
            if ($data !== '' && $data !== '__NOFILE__') {
                Log::info("📄 {$server->name}: using {$path}");
                return $data;
            }
            Log::warning("⚠️ {$server->name}: {$path} not found or empty");
        }

        return '';
    }

    /**
     * Upsert current connections by **username** and disconnect everything else on this server.
     * Returns array of vpn_user_ids that are currently connected.
     */
    protected function upsertConnectionsByUsername(int $serverId, array $connectedByUsername): array
    {
        return DB::transaction(function () use ($serverId, $connectedByUsername) {
            $now       = now();
            $usernames = array_keys($connectedByUsername);

            // Map usernames -> ids (ignore ones not in DB yet)
            /** @var \Illuminate\Support\Collection<string,int> $idByUsername */
            $idByUsername = VpnUser::whereIn('username', $usernames)->pluck('id', 'username');

            $connectedIds = [];

            // Upsert current connections
            foreach ($connectedByUsername as $username => $payload) {
                $uid = $idByUsername[$username] ?? null;
                if (!$uid) {
                    // user record doesn’t exist (yet) – skip quietly
                    continue;
                }
                $connectedIds[] = $uid;

                /** @var VpnUserConnection $row */
                $row = VpnUserConnection::firstOrCreate([
                    'vpn_user_id'   => $uid,
                    'vpn_server_id' => $serverId,
                ]);

                // If was disconnected, set connected_at; if already connected, keep original
                if (!$row->is_connected) {
                    $row->connected_at    = $payload['connected_at'] ?? $now;
                    $row->disconnected_at = null;
                }

                $row->is_connected   = true;
                $row->client_ip      = $payload['client_ip']      ?? $row->client_ip;
                $row->virtual_ip     = $payload['virtual_ip']     ?? $row->virtual_ip;
                $row->bytes_received = $payload['bytes_received'] ?? $row->bytes_received;
                $row->bytes_sent     = $payload['bytes_sent']     ?? $row->bytes_sent;
                $row->save();

                // keep VpnUser hot
                VpnUser::whereKey($uid)->update([
                    'is_online' => true,
                    'last_ip'   => $row->client_ip,
                ]);
            }

            // Disconnect any **other** rows on this server that were previously marked connected
            // but are not in the mgmt list now.
            $toDisconnect = VpnUserConnection::query()
                ->where('vpn_server_id', $serverId)
                ->where('is_connected', true)
                ->when(!empty($connectedIds), fn($q) => $q->whereNotIn('vpn_user_id', $connectedIds))
                ->get(['id', 'vpn_user_id']);

            foreach ($toDisconnect as $row) {
                $row->update([
                    'is_connected'    => false,
                    'disconnected_at' => $now,
                ]);
                VpnUserConnection::updateUserOnlineStatusIfNoActiveConnections($row->vpn_user_id);
            }

            return $connectedIds;
        });
    }

    protected function disconnectAllOnServer(int $serverId): void
    {
        $now = now();
        $rows = VpnUserConnection::where('vpn_server_id', $serverId)
            ->where('is_connected', true)
            ->get(['id', 'vpn_user_id']);

        foreach ($rows as $row) {
            $row->update([
                'is_connected'    => false,
                'disconnected_at' => $now,
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