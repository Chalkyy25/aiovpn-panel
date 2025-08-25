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

    public int $clients = 0;          // always present
    public string $cnList = '';       // always present
    public array $users = [];         // always present
    public string $raw = '';

    /**
     * Constructor is flexible:
     *  - If 3rd arg is array → treat as users[]
     *  - If 3rd arg is int   → treat as client count
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
            // ✅ Modern usage: pass array of usernames
            $this->users   = array_map(fn ($u) =>
                is_string($u) ? ['username' => $u] :
                (is_array($u) ? $u : ['username' => (string) $u]),
            $usersOrCount);

            $this->clients = count($this->users);
            $this->cnList  = $cnList ?: implode(',', array_column($this->users, 'username'));
        } else {
            // ✅ Legacy: pass int count
            $this->clients = (int) $usersOrCount;
            $this->cnList  = (string) ($cnList ?? '');
            $this->users   = $this->cnList !== ''
                ? array_map(fn ($n) => ['username' => $n], array_filter(explode(',', $this->cnList)))
                : [];
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
            'users'     => $this->users,
            'raw'       => $this->raw,
        ];
    }
}