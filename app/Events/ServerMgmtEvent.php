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

    public int $serverId;
    public string $ts;

    public int $clients = 0;     // always present
    public string $cnList = '';  // always present
    /** @var array<int,array> */
    public array $users = [];    // always present
    public string $raw = '';

    /**
     * $usersOrCount can be:
     *  - array of usernames: ["alice", "bob"]
     *  - array of objects:   [{ username, client_ip, virtual_ip, connected_at, bytes_in/out, ... }]
     *  - int count (legacy) with optional $cnList string "alice,bob"
     */
    public function __construct(
        int $serverId,
        string $ts,
        array|int $usersOrCount = [],
        ?string $cnList = null,
        ?string $raw = null
    ) {
        $this->serverId = $serverId;
        $this->ts       = $ts;
        $this->raw      = (string)($raw ?? '');

        if (is_array($usersOrCount)) {
            // Accept ["alice","bob"] OR [{...}]
            $this->users = collect($usersOrCount)->map(function ($u) {
                if (is_string($u)) {
                    $u = ['username' => $u];
                }

                // Normalise to the dashboard shape (keep sensible fallbacks)
                return [
                    'connection_id' => $u['connection_id'] ?? null,
                    'username'      => $u['username'] ?? ($u['cn'] ?? 'unknown'),
                    'client_ip'     => $u['client_ip'] ?? null,
                    'virtual_ip'    => $u['virtual_ip'] ?? null,
                    // ISO8601 strongly recommended
                    'connected_at'  => $u['connected_at'] ?? null,

                    // Normalise bytes_* key names -> bytes_in / bytes_out
                    'bytes_in'      => (int)($u['bytes_in'] ?? $u['bytesReceived'] ?? $u['bytes_received'] ?? 0),
                    'bytes_out'     => (int)($u['bytes_out'] ?? $u['bytesSent'] ?? $u['bytes_sent'] ?? 0),

                    // Optional (Alpine can also derive from meta map)
                    'server_name'   => $u['server_name'] ?? null,
                ];
            })->values()->all();

            $this->clients = count($this->users);
            $names = array_map(fn ($r) => $r['username'] ?? '', $this->users);
            $this->cnList = $cnList ?: implode(',', array_filter($names));
        } else {
            // Legacy: only count + optional "a,b,c"
            $this->clients = (int)$usersOrCount;
            $this->cnList  = (string)($cnList ?? '');
            $this->users   = $this->cnList !== ''
                ? array_map(fn ($n) => ['username' => $n], array_filter(explode(',', $this->cnList)))
                : [];
        }
    }

    /** Broadcast to fleet + per-server */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('servers.dashboard'),
            new PrivateChannel("servers.{$this->serverId}"),
        ];
    }

    /** Echo listens to ".mgmt.update" */
    public function broadcastAs(): string
    {
        return 'mgmt.update';
    }

    /** Payload shape Alpine expects */
    public function broadcastWith(): array
    {
        return [
            'server_id' => $this->serverId,
            'ts'        => $this->ts,
            'clients'   => $this->clients,
            'cn_list'   => $this->cnList,
            'users'     => $this->users, // already normalised: bytes_in/out, connected_at, etc.
            'raw'       => $this->raw,
        ];
    }
}