<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerMgmtEvent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public int $serverId;
    public string $ts;

    /** rich rows: [{ username, client_ip, virtual_ip, connected_at, bytes_in, bytes_out, server_name? }] */
    public array $users = [];

    public int $clients = 0;
    public string $cnList = '';
    public string $raw = '';

    /**
     * Pass a fully built $users array. We keep clients/cnList for display/logging.
     */
    public function __construct(
        int $serverId,
        string $ts,
        array $users = [],
        ?string $cnList = null,
        ?string $raw = null
    ) {
        $this->serverId = $serverId;
        $this->ts       = $ts;

        $this->users   = array_values($users);
        $this->clients = count($this->users);
        $this->cnList  = $cnList ?? implode(',', array_column($this->users, 'username'));
        $this->raw     = (string) ($raw ?? '');
    }

    /** broadcast to fleet + per-server */
    public function broadcastOn(): array
    {
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
            'cn_list'   => $this->cnList,
            'users'     => $this->users,
            'raw'       => $this->raw,
        ];
    }
}