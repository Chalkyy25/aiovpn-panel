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

    /** Total clients (derived if not provided) */
    public int $clients = 0;

    /** Comma-separated CNs (derived if not provided) */
    public string $cnList = '';

    /** Rich users payload for the UI (optional) */
    public array $users = [];

    /** Free-form text for debugging (optional) */
    public string $raw = '';

    /**
     * Backward-compatible constructor:
     *  - 3rd arg can be an array of users OR a client count (int)
     *  - If an int is passed, you can optionally pass $cnList in the 4th arg
     */
    public function __construct(
        int $serverId,
        string $ts,
        $usersOrCount = [],
        ?string $cnList = null,
        ?string $raw = null
    ) {
        $this->serverId = $serverId;
        $this->ts       = $ts;

        if (is_array($usersOrCount)) {
            $this->users   = $usersOrCount;
            $this->clients = count($usersOrCount);
            // derive cnList if not provided
            $this->cnList  = $cnList ?? implode(',', array_filter(array_map(function ($u) {
                // allow string usernames or arrays with username/cn
                if (is_string($u)) return $u;
                if (is_array($u))  return $u['username'] ?? $u['cn'] ?? null;
                return null;
            }, $this->users)));
        } else {
            // old style: (serverId, ts, clients:int, cnList?:string, raw?:string)
            $this->clients = (int) $usersOrCount;
            $this->cnList  = (string) ($cnList ?? '');
        }

        $this->raw = (string) ($raw ?? '');
    }

    public function broadcastOn(): PrivateChannel
{
    return new PrivateChannel("servers.{$this->serverId}");
    
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
            'cn_list'   => $this->cnList,
            // include users if we have them (UI handles both)
            'users'     => $this->users,
            'raw'       => $this->raw,
        ];
    }
}