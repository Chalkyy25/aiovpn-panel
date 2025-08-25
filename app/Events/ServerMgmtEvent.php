<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerMgmtEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Server numeric ID */
    public int $serverId;

    /** ISO8601 timestamp string */
    public string $ts;

    /** Normalized users array (each item is at least ['username'=>..]) */
    public array $users = [];

    /** Convenience count */
    public int $clients = 0;

    /** Back-compat string of usernames, comma-separated */
    public string $cnList = '';

    /** Optional source/debug tag (e.g. 'sync-job', 'mgmt', etc.) */
    public string $source;

    /**
     * @param int                     $serverId
     * @param \DateTimeInterface|string $ts
     * @param array<int, array|string>  $users   Array of strings or objects:
     *        [
     *          'username'        => string,
     *          'client_ip'       => ?string,
     *          'virtual_ip'      => ?string,
     *          'bytes_received'  => ?int,
     *          'bytes_sent'      => ?int,
     *          'connected_at'    => ?int,
     *          'connected_fmt'   => ?string,
     *          'connected_human' => ?string,
     *          'down_mb'         => ?float,
     *          'up_mb'           => ?float,
     *          'formatted_bytes' => ?string,
     *          'connection_id'   => ?int,
     *        ]
     * @param string $source
     */
    public function __construct(int $serverId, $ts, array $users = [], string $source = 'sync-job')
    {
        $this->serverId = $serverId;
        $this->ts       = $ts instanceof \DateTimeInterface ? $ts->format(DATE_ATOM) : (string)$ts;
        $this->users    = $this->normalizeUsers($users);
        $this->clients  = count($this->users);
        $this->cnList   = implode(',', array_map(fn ($u) => $u['username'] ?? '', $this->users));
        $this->source   = $source;
    }

    public function broadcastOn(): array
    {
        // Per-server channel + fleet dashboard channel (your Alpine listens to both)
        return [
            new PrivateChannel("servers.{$this->serverId}"),
            new PrivateChannel('servers.dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'mgmt.update';
    }

    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'ts'        => $this->ts,
            'clients'   => $this->clients,
            'users'     => $this->users,   // ← rich objects for the UI
            'cn_list'   => $this->cnList,  // ← back-compat
            'source'    => $this->source,
        ];
    }

    /** @param array<int, array|string> $users */
    private function normalizeUsers(array $users): array
    {
        $out = [];
        foreach ($users as $u) {
            if (is_string($u)) {
                $out[] = ['username' => $u];
                continue;
            }
            if (is_array($u)) {
                // keep expected keys only; ignore unknowns to keep payload tidy
                $out[] = [
                    'username'        => $u['username']        ?? null,
                    'client_ip'       => $u['client_ip']       ?? null,
                    'virtual_ip'      => $u['virtual_ip']      ?? null,
                    'bytes_received'  => $u['bytes_received']  ?? null,
                    'bytes_sent'      => $u['bytes_sent']      ?? null,
                    'connected_at'    => $u['connected_at']    ?? null,
                    'connected_fmt'   => $u['connected_fmt']   ?? null,
                    'connected_human' => $u['connected_human'] ?? null,
                    'down_mb'         => $u['down_mb']         ?? null,
                    'up_mb'           => $u['up_mb']           ?? null,
                    'formatted_bytes' => $u['formatted_bytes'] ?? null,
                    'connection_id'   => $u['connection_id']   ?? null,
                ];
            }
        }
        // drop empties / missing usernames
        return array_values(array_filter($out, fn ($r) => !empty($r['username'])));
    }
}
