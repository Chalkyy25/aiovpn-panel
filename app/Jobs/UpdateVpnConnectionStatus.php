<?php

namespace App\Jobs;

use App\Models\VpnServer;
use App\Models\VpnUserConnection;
use App\Services\OpenVpnStatusParser;
use App\Traits\ExecutesRemoteCommands;
use App\Events\ServerMgmtEvent;
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
     * How we behave if we cannot read a status file this run.
     * - true  = mark everyone offline (strict)
     * - false = skip updates this server to avoid false negatives
     */
    protected bool $strictOfflineOnMissing = false;

    public function handle(): void
    {
        Log::info('🔄 Hybrid sync: updating VPN connection status');

        /** @var Collection<int,VpnServer> $servers */
        $servers = VpnServer::query()
            ->where('deployment_status', 'succeeded')
            ->get();

        if ($servers->isEmpty()) {
            Log::info('⚠️ No succeeded VPN servers found.');
            return;
        }

        foreach ($servers as $server) {
            $this->syncOneServer($server);
        }

        Log::info('✅ Hybrid sync completed');
    }

    protected function syncOneServer(VpnServer $server): void
    {
        try {
            $raw = $this->fetchOpenVpnStatusLog($server);

            if ($raw === '') {
                Log::warning("⚠️ {$server->name}: status file not readable this run.");
                if ($this->strictOfflineOnMissing) {
                    $this->markAllUsersDisconnected($server);
                    $this->broadcastSnapshot($server->id, now(), []);
                }
                // not strict: do nothing, keep last snapshot to avoid flapping
                return;
            }

            // Auto-detect v2/v3 via your service (falls back to manual parser if needed)
            $parsed = OpenVpnStatusParser::parse($raw);
            $connected = []; // username => details

            foreach ($parsed['clients'] as $c) {
                $username = (string)($c['username'] ?? '');
                if ($username === '') {
                    continue;
                }
                $connected[$username] = [
                    'client_ip'      => $c['client_ip'] ?? null,
                    'bytes_received' => (int)($c['bytes_received'] ?? 0),
                    'bytes_sent'     => (int)($c['bytes_sent'] ?? 0),
                    'connected_at'   => isset($c['connected_at'])
                        ? Carbon::createFromTimestamp($c['connected_at'])
                        : null,
                ];
            }

            // Persist snapshot in DB
            $this->upsertConnections($server, $connected);

            // Optional server counters (if these columns exist)
            try {
                $server->forceFill([
                    'online_users' => count($connected),
                    'last_sync_at' => now(),
                ])->saveQuietly();
            } catch (\Throwable $e) {
                // counters are optional – ignore if columns not present
            }

            // Broadcast snapshot for Reverb/Echo
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
     * Read OpenVPN status (tries common v3/v2 paths over SSH).
     */
    protected function fetchOpenVpnStatusLog(VpnServer $server): string
    {
        $candidates = [
            '/run/openvpn/server.status',   // systemd (v3)
            '/var/log/openvpn-status.log',  // classic (v2)
        ];

        foreach ($candidates as $path) {
            $cmd = 'test -r '.escapeshellarg($path).' && cat '.escapeshellarg($path).' || echo "__NOFILE__"';
            $res = $this->executeRemoteCommand($server->ip_address, $cmd);

            if (($res['status'] ?? 1) !== 0) {
                continue;
            }

            $out = trim(implode("\n", $res['output'] ?? []));
            if ($out !== '' && $out !== '__NOFILE__') {
                return $out;
            }
        }

        return '';
    }

    /**
     * Persist connections snapshot to DB (idempotent).
     */
    protected function upsertConnections(VpnServer $server, array $connected): void
    {
        DB::transaction(function () use ($server, $connected) {
            // Index for quick lookups
            $connectedUsernames = array_keys($connected);

            // All known users on this server (through relation)
            $serverUsers = $server->vpnUsers()->get(['id', 'username']);

            foreach ($serverUsers as $user) {
                $row = VpnUserConnection::firstOrCreate([
                    'vpn_user_id'   => $user->id,
                    'vpn_server_id' => $server->id,
                ]);

                if (in_array($user->username, $connectedUsernames, true)) {
                    $u = $connected[$user->username];

                    // Flip online + update metrics
                    if (!$row->is_connected) {
                        $row->connected_at    = $u['connected_at'] ?? now();
                        $row->disconnected_at = null;
                    }

                    $row->is_connected   = true;
                    $row->client_ip      = $u['client_ip']      ?? $row->client_ip;
                    $row->bytes_received = $u['bytes_received'] ?? $row->bytes_received;
                    $row->bytes_sent     = $u['bytes_sent']     ?? $row->bytes_sent;
                    $row->save();

                    // user flags
                    $user->forceFill([
                        'is_online' => true,
                        'last_ip'   => $row->client_ip,
                    ])->save();

                } else {
                    // Not present in snapshot -> mark this connection offline if needed
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
     * Hard mark everything offline for a server (used only in strict mode / errors).
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
     * Emit a compact snapshot to the private channel the UI is listening on.
     * Front-end accepts either `users: []` (strings) or `cn_list: "a,b"`.
     */
    protected function broadcastSnapshot(int $serverId, \DateTimeInterface $ts, array $usernames): void
    {
        // Your ServerMgmtEvent -> broadcastAs('mgmt.update') on channel "servers.{id}"
        broadcast(new ServerMgmtEvent(
            $serverId,
            $ts->format(DATE_ATOM),
            count($usernames),
            implode(',', $usernames),
            'sync-job'
        ));
    }
}